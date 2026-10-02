<?php

namespace App\Inventory;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\NumberSequence;
use App\Models\StockAdjustment;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Posts a stock adjustment: applies each line to the stock ledger and books a
 * single balanced journal — inventory accounts against the chosen offset
 * account (variance, opening equity, damage expense, …).
 */
class StockAdjustmentService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        if ($adjustment->isPosted()) {
            throw new InventoryException('This adjustment is already posted.');
        }

        $adjustment->load('lines.item', 'lines.warehouse');

        if ($adjustment->lines->isEmpty()) {
            throw new InventoryException('Add at least one line before posting.');
        }
        if (! $adjustment->offset_account_id) {
            throw new InventoryException('Choose an offset account before posting.');
        }

        return DB::transaction(function () use ($adjustment) {
            $date = $adjustment->adjustment_date->toDateString();
            $inventoryNet = [];   // account_id => net debit
            $offsetNet = 0.0;     // net debit on the offset account

            foreach ($adjustment->lines as $line) {
                $item = $line->item;
                $warehouse = $line->warehouse;
                $accountId = $item->inventory_account_id;

                if (! $accountId) {
                    throw new InventoryException("Item {$item->code} has no inventory account mapped.");
                }

                $meta = [
                    'posting_date' => $date,
                    'entry_type' => $adjustment->reason === 'opening' ? 'opening' : 'adjustment',
                    'source' => $adjustment,
                    'voucher_no' => $adjustment->number,
                    'remarks' => $line->description,
                ];

                $qty = (float) $line->quantity;

                if ($qty > 0) {
                    $this->inventory->receive($item, $warehouse, $qty, (float) $line->rate, $meta);
                    $value = round($qty * (float) $line->rate, 4);
                    $inventoryNet[$accountId] = round(($inventoryNet[$accountId] ?? 0) + $value, 4);
                    $offsetNet = round($offsetNet - $value, 4);
                } elseif ($qty < 0) {
                    $sle = $this->inventory->issue($item, $warehouse, abs($qty), $meta);
                    $value = abs((float) $sle->value);
                    $inventoryNet[$accountId] = round(($inventoryNet[$accountId] ?? 0) - $value, 4);
                    $offsetNet = round($offsetNet + $value, 4);
                }
            }

            $lines = [];
            foreach ($inventoryNet as $accountId => $net) {
                if ($net > 0.005) {
                    $lines[] = LedgerLine::debit((int) $accountId, $net, 'Stock adjustment');
                } elseif ($net < -0.005) {
                    $lines[] = LedgerLine::credit((int) $accountId, -$net, 'Stock adjustment');
                }
            }
            if ($offsetNet > 0.005) {
                $lines[] = LedgerLine::debit($adjustment->offset_account_id, $offsetNet, 'Stock adjustment offset');
            } elseif ($offsetNet < -0.005) {
                $lines[] = LedgerLine::credit($adjustment->offset_account_id, -$offsetNet, 'Stock adjustment offset');
            }

            $number = $this->allocateNumber();

            $journalId = null;
            if (count($lines) >= 2) {
                $journal = $this->posting->post(new LedgerEntry(
                    entryDate: $date,
                    lines: $lines,
                    type: 'stock_adjustment',
                    reference: $number,
                    memo: $adjustment->memo ?: 'Stock adjustment',
                    source: $adjustment,
                ));
                $journalId = $journal->id;
                $this->inventory->stampJournal($adjustment, $journalId);
            }

            $adjustment->update([
                'number' => $number,
                'status' => 'posted',
                'journal_id' => $journalId,
                'posted_at' => now(),
            ]);

            return $adjustment;
        });
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'stock_adjustment'],
            ['prefix' => 'ADJ-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('stock_adjustment');
    }
}
