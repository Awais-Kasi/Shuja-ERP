<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockReportController extends Controller
{
    public function balances(Request $request): Response
    {
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $balances = StockBalance::query()
            ->with(['item:id,code,name,type', 'warehouse:id,code,name'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where(fn ($q) => $q->where('quantity', '<>', 0)->orWhere('value', '<>', 0))
            ->get()
            ->sortBy(fn ($b) => $b->item->code)
            ->values()
            ->map(fn (StockBalance $b) => [
                'item' => $b->item->code.' — '.$b->item->name,
                'item_id' => $b->item_id,
                'warehouse' => $b->warehouse->code,
                'quantity' => (float) $b->quantity,
                'value' => (float) $b->value,
                'rate' => $b->averageRate(),
            ]);

        return Inertia::render('inventory/stock-balance', [
            'balances' => $balances,
            'totalValue' => round($balances->sum('value'), 2),
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'filters' => ['warehouse_id' => $warehouseId],
        ]);
    }

    public function ledger(Request $request): Response
    {
        $itemId = $request->integer('item_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $entries = null;
        if ($itemId) {
            $entries = StockLedgerEntry::query()
                ->with('warehouse:id,code')
                ->where('item_id', $itemId)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->orderBy('posting_date')
                ->orderBy('id')
                ->get()
                ->map(fn (StockLedgerEntry $e) => [
                    'date' => $e->posting_date->toDateString(),
                    'warehouse' => $e->warehouse->code,
                    'entry_type' => $e->entry_type,
                    'voucher' => $e->voucher_no,
                    'quantity' => (float) $e->quantity,
                    'rate' => (float) $e->rate,
                    'value' => (float) $e->value,
                    'balance_qty' => (float) $e->balance_qty,
                    'balance_value' => (float) $e->balance_value,
                ]);
        }

        return Inertia::render('inventory/stock-ledger', [
            'items' => Item::where('tracks_inventory', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($i) => ['id' => $i->id, 'label' => $i->code.' — '.$i->name]),
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            'filters' => ['item_id' => $itemId, 'warehouse_id' => $warehouseId],
            'entries' => $entries,
        ]);
    }
}
