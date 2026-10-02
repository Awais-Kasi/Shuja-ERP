<?php

namespace App\Inventory;

use App\Enums\ValuationMethod;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockFifoLayer;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records stock movements into the append-only stock ledger and maintains the
 * cached per-(item, warehouse) balance and FIFO layers. It computes the value
 * of each movement using the item's valuation method (FIFO or Weighted
 * Average). It does NOT touch the general ledger — callers collect the value
 * impact and post one journal per document through the PostingEngine.
 *
 * Callers must wrap invocations in a DB transaction; balance rows are locked.
 */
class InventoryService
{
    public function __construct(private readonly TenantManager $tenant) {}

    /**
     * An incoming movement at a known unit rate (opening, receipt, positive
     * adjustment, transfer-in). Returns the created ledger entry.
     *
     * @param  array<string, mixed>  $meta  posting_date, entry_type, source, voucher_no, remarks, journal_id
     */
    public function receive(Item $item, Warehouse $warehouse, float $qty, float $rate, array $meta = []): StockLedgerEntry
    {
        if ($qty <= 0) {
            throw new InventoryException('Receive quantity must be positive.');
        }

        $method = $item->valuationMethod();
        $value = round($qty * $rate, 4);

        $balance = $this->lockedBalance($item, $warehouse);
        $balance->quantity = round((float) $balance->quantity + $qty, 4);
        $balance->value = round((float) $balance->value + $value, 4);
        $balance->save();

        $sle = $this->writeEntry($item, $warehouse, $qty, $rate, $value, $balance, $method, $meta);

        if ($method === ValuationMethod::Fifo) {
            StockFifoLayer::create([
                'company_id' => $this->companyId(),
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'sle_id' => $sle->id,
                'posting_date' => $sle->posting_date,
                'rate' => $rate,
                'original_qty' => $qty,
                'remaining_qty' => $qty,
            ]);
        }

        return $sle;
    }

    /**
     * An outgoing movement (issue, negative adjustment, transfer-out). The unit
     * rate is derived from the valuation method. Returns the created ledger
     * entry (its `value` is the cost relieved from stock, useful for GL/COGS).
     *
     * @param  array<string, mixed>  $meta
     */
    public function issue(Item $item, Warehouse $warehouse, float $qty, array $meta = []): StockLedgerEntry
    {
        if ($qty <= 0) {
            throw new InventoryException('Issue quantity must be positive.');
        }

        $method = $item->valuationMethod();
        $balance = $this->lockedBalance($item, $warehouse);
        $available = (float) $balance->quantity;

        if ($qty > $available + 1e-9 && ! $this->allowsNegativeStock()) {
            throw new InventoryException(
                "Insufficient stock for {$item->code} in {$warehouse->code}: have ".rtrim(rtrim(number_format($available, 4), '0'), '.').", need {$qty}."
            );
        }

        $value = $method === ValuationMethod::Fifo
            ? $this->consumeFifo($item, $warehouse, $qty)
            : $this->consumeWeightedAverage($balance, $qty);

        $rate = $qty != 0.0 ? round($value / $qty, 4) : 0.0;

        $balance->quantity = round($available - $qty, 4);
        $balance->value = round((float) $balance->value - $value, 4);
        $balance->save();

        return $this->writeEntry($item, $warehouse, -$qty, $rate, -$value, $balance, $method, $meta);
    }

    /**
     * Capitalise a landed cost (freight, handling, …) onto existing stock:
     * raises value with quantity unchanged. For FIFO items each remaining
     * layer's rate is bumped pro-rata so the cost reaches COGS only for units
     * actually issued later. Writes a qty=0 audit entry. WAC needs no extra
     * work — unit cost = value/qty rises automatically.
     *
     * @param  array<string, mixed>  $meta
     */
    public function addLandedCost(Item $item, Warehouse $warehouse, float $amount, array $meta = []): StockLedgerEntry
    {
        $amount = round($amount, 4);
        if ($amount <= 0) {
            throw new InventoryException('Landed cost amount must be positive.');
        }

        $method = $item->valuationMethod();
        $balance = $this->lockedBalance($item, $warehouse);
        if ((float) $balance->quantity <= 0) {
            throw new InventoryException("Cannot capitalise landed cost onto {$item->code} in {$warehouse->code}: no stock on hand.");
        }

        $balance->value = round((float) $balance->value + $amount, 4);
        $balance->save();

        if ($method === ValuationMethod::Fifo) {
            $layers = StockFifoLayer::query()
                ->where('item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('remaining_qty', '>', 0)
                ->orderBy('posting_date')->orderBy('id')
                ->lockForUpdate()
                ->get();

            $totalRemaining = 0.0;
            foreach ($layers as $layer) {
                $totalRemaining += (float) $layer->remaining_qty;
            }

            if ($totalRemaining > 0) {
                // Carry the rate-rounding residual forward so a 4dp rate never silently
                // loses capitalised cost; the final layer absorbs whatever is left.
                $remainingAmount = $amount;
                $last = $layers->count() - 1;
                foreach ($layers as $i => $layer) {
                    $rem = (float) $layer->remaining_qty;
                    $share = $i === $last
                        ? $remainingAmount
                        : round($amount * ($rem / $totalRemaining), 4);
                    $newRate = round(((float) $layer->rate * $rem + $share) / $rem, 4);
                    $appliedToLayer = round(($newRate - (float) $layer->rate) * $rem, 4);
                    $remainingAmount = round($remainingAmount - $appliedToLayer, 4);
                    $layer->rate = $newRate;
                    $layer->save();
                }
            }
        }

        return $this->writeEntry($item, $warehouse, 0, 0, $amount, $balance, $method, array_merge(['entry_type' => 'landed_cost'], $meta));
    }

    /**
     * Reverse a previously capitalised landed cost: lowers value with quantity
     * unchanged, the exact inverse of {@see addLandedCost()}. For FIFO each remaining
     * layer's rate is reduced pro-rata (never below zero). Safe only while the stock the
     * cost was added to is still on hand — callers must guard against later issues.
     *
     * @param  array<string, mixed>  $meta
     */
    public function removeLandedCost(Item $item, Warehouse $warehouse, float $amount, array $meta = []): StockLedgerEntry
    {
        $amount = round($amount, 4);
        if ($amount <= 0) {
            throw new InventoryException('Landed cost reversal amount must be positive.');
        }

        $method = $item->valuationMethod();
        $balance = $this->lockedBalance($item, $warehouse);
        if ((float) $balance->quantity <= 0) {
            throw new InventoryException("Cannot reverse landed cost on {$item->code} in {$warehouse->code}: no stock on hand.");
        }
        if ($amount > (float) $balance->value + 1e-9) {
            throw new InventoryException("Landed cost reversal exceeds the stock value of {$item->code} in {$warehouse->code}.");
        }

        $balance->value = round((float) $balance->value - $amount, 4);
        $balance->save();

        if ($method === ValuationMethod::Fifo) {
            $layers = StockFifoLayer::query()
                ->where('item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('remaining_qty', '>', 0)
                ->orderBy('posting_date')->orderBy('id')
                ->lockForUpdate()
                ->get();

            $totalRemaining = 0.0;
            foreach ($layers as $layer) {
                $totalRemaining += (float) $layer->remaining_qty;
            }

            if ($totalRemaining > 0) {
                $remainingAmount = $amount;
                $last = $layers->count() - 1;
                foreach ($layers as $i => $layer) {
                    $rem = (float) $layer->remaining_qty;
                    $share = $i === $last
                        ? $remainingAmount
                        : round($amount * ($rem / $totalRemaining), 4);
                    $newRate = round(((float) $layer->rate * $rem - $share) / $rem, 4);
                    if ($newRate < 0) {
                        $newRate = 0.0;
                    }
                    $appliedToLayer = round(((float) $layer->rate - $newRate) * $rem, 4);
                    $remainingAmount = round($remainingAmount - $appliedToLayer, 4);
                    $layer->rate = $newRate;
                    $layer->save();
                }
            }
        }

        return $this->writeEntry($item, $warehouse, 0, 0, -$amount, $balance, $method, array_merge(['entry_type' => 'landed_cost_reversal'], $meta));
    }

    private function consumeWeightedAverage(StockBalance $balance, float $qty): float
    {
        $available = (float) $balance->quantity;
        $rate = $available != 0.0 ? (float) $balance->value / $available : 0.0;

        return round($qty * $rate, 4);
    }

    private function consumeFifo(Item $item, Warehouse $warehouse, float $qty): float
    {
        $needed = $qty;
        $cost = 0.0;

        $layers = StockFifoLayer::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('posting_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($needed <= 1e-9) {
                break;
            }
            $take = min($needed, (float) $layer->remaining_qty);
            $cost = round($cost + $take * (float) $layer->rate, 4);
            $layer->remaining_qty = round((float) $layer->remaining_qty - $take, 4);
            $layer->save();
            $needed = round($needed - $take, 4);
        }

        // Negative stock (only reachable when allowed): value the shortfall at
        // the most recent known rate, or zero.
        if ($needed > 1e-9) {
            $lastRate = (float) (StockFifoLayer::query()
                ->where('item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)
                ->orderByDesc('id')
                ->value('rate') ?? 0);
            $cost = round($cost + $needed * $lastRate, 4);
        }

        return $cost;
    }

    private function writeEntry(Item $item, Warehouse $warehouse, float $qty, float $rate, float $value, StockBalance $balance, ValuationMethod $method, array $meta): StockLedgerEntry
    {
        return StockLedgerEntry::create([
            'company_id' => $this->companyId(),
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'posting_date' => $meta['posting_date'] ?? now()->toDateString(),
            'entry_type' => $meta['entry_type'] ?? 'adjustment',
            'quantity' => round($qty, 4),
            'rate' => round($rate, 4),
            'value' => round($value, 4),
            'balance_qty' => $balance->quantity,
            'balance_value' => $balance->value,
            'valuation_method' => $method->value,
            'journal_id' => $meta['journal_id'] ?? null,
            'source_type' => isset($meta['source']) ? $meta['source']->getMorphClass() : null,
            'source_id' => isset($meta['source']) ? $meta['source']->getKey() : null,
            'voucher_no' => $meta['voucher_no'] ?? null,
            'remarks' => $meta['remarks'] ?? null,
            'created_by' => Auth::id(),
        ]);
    }

    private function lockedBalance(Item $item, Warehouse $warehouse): StockBalance
    {
        $balance = StockBalance::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();

        return $balance ?? new StockBalance([
            'company_id' => $this->companyId(),
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 0,
            'value' => 0,
        ]);
    }

    public function stampJournal(Model $source, int $journalId): void
    {
        StockLedgerEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->update(['journal_id' => $journalId]);
    }

    private function allowsNegativeStock(): bool
    {
        return (bool) ($this->tenant->get()?->allow_negative_stock);
    }

    private function companyId(): int
    {
        return $this->tenant->id();
    }
}
