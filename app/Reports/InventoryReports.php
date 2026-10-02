<?php

namespace App\Reports;

use App\Models\StockBalance;
use App\Models\StockFifoLayer;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inventory valuation (current holdings at cost) and stock aging (value bucketed by how
 * long it has been held — FIFO layers for FIFO items, last-receipt date for weighted-avg).
 */
class InventoryReports
{
    public function __construct(private readonly TenantManager $tenant) {}

    public function valuation(?int $warehouseId = null): array
    {
        $balances = StockBalance::query()
            ->where('quantity', '!=', 0)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->with(['item:id,code,name', 'warehouse:id,code,name'])
            ->get();

        $rows = $balances->map(function (StockBalance $b) {
            $qty = (float) $b->quantity;
            $value = (float) $b->value;

            return [
                'code' => $b->item?->code, 'name' => $b->item?->name, 'warehouse' => $b->warehouse?->code,
                'quantity' => round($qty, 4), 'rate' => $qty != 0.0 ? round($value / $qty, 4) : 0.0, 'value' => round($value, 2),
            ];
        })->sortBy([['code', 'asc'], ['warehouse', 'asc']])->values()->all();

        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'value')), 2)];
    }

    public function aging(string $asOf): array
    {
        $asOfC = Carbon::parse($asOf);
        $rows = []; // key item_id:warehouse_id

        $blank = fn ($code, $name, $wh) => ['code' => $code, 'name' => $name, 'warehouse' => $wh, 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
        // Stock has no "not yet due" concept, so 0–30 days falls in the first bucket.
        $add = function (array &$row, int $daysPast, float $value): void {
            $b = match (true) {
                $daysPast <= 30 => 'd1_30',
                $daysPast <= 60 => 'd31_60',
                $daysPast <= 90 => 'd61_90',
                default => 'd90_plus',
            };
            $row[$b] = round($row[$b] + $value, 2);
            $row['total'] = round($row['total'] + $value, 2);
        };

        // Last inbound movement per item+warehouse — the age used for weighted-average
        // stock and for any FIFO residual not covered by layers.
        $lastInbound = [];
        foreach (DB::table('stock_ledger_entries')
            ->where('company_id', $this->tenant->id())
            ->where('quantity', '>', 0)
            ->whereDate('posting_date', '<=', $asOf)
            ->groupBy('item_id', 'warehouse_id')
            ->select('item_id', 'warehouse_id', DB::raw('MAX(posting_date) as last_in'))
            ->get() as $r) {
            $lastInbound[$r->item_id.':'.$r->warehouse_id] = $r->last_in;
        }
        $lastInboundDays = fn (string $key) => isset($lastInbound[$key]) ? Carbon::parse($lastInbound[$key])->diffInDays($asOfC) : 0;

        // FIFO remaining layers grouped by item+warehouse.
        $layersByKey = [];
        foreach (StockFifoLayer::query()->where('remaining_qty', '>', 0.0001)->whereDate('posting_date', '<=', $asOf)->get() as $layer) {
            $layersByKey[$layer->item_id.':'.$layer->warehouse_id][] = [
                'value' => round((float) $layer->remaining_qty * (float) $layer->rate, 2),
                'days' => Carbon::parse($layer->posting_date)->diffInDays($asOfC),
            ];
        }

        // Drive off StockBalance (the value source of truth) so the report always
        // reconciles to the valuation total, whatever the valuation method.
        $balances = StockBalance::query()->where('quantity', '!=', 0)
            ->with(['item:id,code,name,valuation_method', 'warehouse:id,code,name'])->get();

        foreach ($balances as $b) {
            $key = $b->item_id.':'.$b->warehouse_id;
            $row = $blank($b->item?->code, $b->item?->name, $b->warehouse?->code);
            $value = round((float) $b->value, 2);

            if (($b->item?->valuation_method?->value ?? $b->item?->valuation_method) === 'fifo' && ! empty($layersByKey[$key])) {
                $layerSum = 0.0;
                foreach ($layersByKey[$key] as $l) {
                    $add($row, $l['days'], $l['value']);
                    $layerSum = round($layerSum + $l['value'], 2);
                }
                $residual = round($value - $layerSum, 2); // landed-cost drift, etc.
                if (abs($residual) > 0.01) {
                    $add($row, $lastInboundDays($key), $residual);
                }
            } else {
                $add($row, $lastInboundDays($key), $value);
            }

            $rows[$key] = $row;
        }

        $out = array_values($rows);
        usort($out, fn ($a, $c) => [$a['code'], $a['warehouse']] <=> [$c['code'], $c['warehouse']]);

        $totals = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
        foreach ($out as $r) {
            foreach (array_keys($totals) as $k) {
                $totals[$k] = round($totals[$k] + $r[$k], 2);
            }
        }

        return ['as_of' => $asOf, 'rows' => $out, 'totals' => $totals];
    }
}
