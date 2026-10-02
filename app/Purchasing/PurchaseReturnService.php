<?php

namespace App\Purchasing;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\PurchaseBillLine;
use App\Models\PurchaseReturn;
use App\Models\StockLedgerEntry;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Posts a purchase return (debit note): goods are shipped back to the supplier out of
 * stock at their carried cost, input tax is reversed, and Accounts Payable is reduced.
 *
 *   Dr Accounts Payable  (total, party = supplier)
 *       Cr Inventory      (cost, per item inventory account)
 *       Cr Input Tax      (tax)
 */
class PurchaseReturnService
{
    private const EPSILON = 1e-4;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(PurchaseReturn $return): PurchaseReturn
    {
        if ($return->status !== 'draft') {
            throw new PurchasingException('This return is already posted.');
        }

        $return->load('lines.item', 'lines.billLine', 'supplier', 'warehouse');
        if ($return->lines->isEmpty()) {
            throw new PurchasingException('Add at least one line before posting.');
        }

        $payable = $return->supplier->payable_account_id
            ? Account::find($return->supplier->payable_account_id)
            : Account::where('control_type', 'ap')->first();
        if (! $payable) {
            throw new PurchasingException('No Accounts Payable control account is configured.');
        }

        return DB::transaction(function () use ($return, $payable) {
            $date = $return->return_date->toDateString();
            $number = $this->allocateNumber();

            $inventoryByAccount = [];
            $subtotal = 0.0;

            foreach ($return->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    throw new PurchasingException('Return quantities must be positive.');
                }
                if (! $item->inventory_account_id) {
                    throw new PurchasingException("Item {$item->code} has no inventory account mapped.");
                }

                if ($line->purchase_bill_line_id) {
                    $bl = PurchaseBillLine::whereKey($line->purchase_bill_line_id)->lockForUpdate()->first();
                    if ($bl) {
                        $remaining = round((float) $bl->quantity - (float) $bl->returned_qty, 4);
                        if ($qty - $remaining > self::EPSILON) {
                            throw new PurchasingException("Cannot return {$qty} of {$item->code}: only {$remaining} remain on the bill.");
                        }
                        $bl->update(['returned_qty' => round((float) $bl->returned_qty + $qty, 4)]);
                    }
                }

                // Relieve stock at carried cost.
                $sle = $this->inventory->issue($item, $return->warehouse, $qty, [
                    'posting_date' => $date, 'entry_type' => 'issue', 'source' => $return,
                    'voucher_no' => $number, 'remarks' => 'Purchase return',
                ]);
                $cost = abs((float) $sle->value);
                $line->update(['rate' => $qty > 0 ? round($cost / $qty, 4) : 0, 'amount' => $cost, 'cost' => $cost]);
                $subtotal = round($subtotal + $cost, 4);
                $inventoryByAccount[$item->inventory_account_id] = round(($inventoryByAccount[$item->inventory_account_id] ?? 0) + $cost, 4);
            }

            $tax = round((float) $return->tax_amount, 4);
            $total = round($subtotal + $tax, 4);

            $lines = [];
            $lines[] = new LedgerLine(accountId: $payable->id, debit: $total, description: "Debit note {$number}", partyType: $return->supplier->getMorphClass(), partyId: $return->supplier->id);
            foreach ($inventoryByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Goods returned to supplier');
                }
            }
            if ($tax > self::EPSILON) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'asset')->first();
                if (! $taxAccount) {
                    throw new PurchasingException('No input-tax control account is configured.');
                }
                $lines[] = LedgerLine::credit($taxAccount->id, $tax, 'Input tax reversed');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date, lines: $lines, type: 'purchase_return', reference: $number,
                memo: $return->memo ?: "Purchase return {$number}", source: $return,
            ));
            $this->inventory->stampJournal($return, $journal->id);

            $return->update([
                'number' => $number, 'status' => 'posted', 'subtotal' => $subtotal,
                'tax_amount' => $tax, 'total' => $total, 'journal_id' => $journal->id, 'posted_at' => now(),
            ]);

            return $return;
        });
    }

    public function reverse(PurchaseReturn $return): PurchaseReturn
    {
        if ($return->isReversed()) {
            throw new PurchasingException('This return is already reversed.');
        }
        if (! $return->isPosted()) {
            throw new PurchasingException('Only a posted return can be reversed.');
        }

        $return->load('lines.item', 'lines.billLine', 'supplier', 'warehouse');

        return DB::transaction(function () use ($return) {
            $locked = PurchaseReturn::whereKey($return->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new PurchasingException('This return can no longer be reversed.');
            }

            $payable = $return->supplier->payable_account_id
                ? Account::find($return->supplier->payable_account_id)
                : Account::where('control_type', 'ap')->first();

            $date = now()->toDateString();
            $sleIds = [];
            $inventoryByAccount = [];
            $subtotal = 0.0;

            foreach ($return->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                $unitCost = $qty > 0 ? round((float) $line->cost / $qty, 6) : 0.0;

                $in = $this->inventory->receive($item, $return->warehouse, $qty, $unitCost, [
                    'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $return,
                    'voucher_no' => $return->number, 'remarks' => 'Purchase return reversal',
                ]);
                $sleIds[] = $in->id;
                $cost = round((float) $line->cost, 4);
                $subtotal = round($subtotal + $cost, 4);
                $inventoryByAccount[$item->inventory_account_id] = round(($inventoryByAccount[$item->inventory_account_id] ?? 0) + $cost, 4);

                if ($line->purchase_bill_line_id && $line->billLine) {
                    $bl = PurchaseBillLine::whereKey($line->purchase_bill_line_id)->lockForUpdate()->first();
                    if ($bl) {
                        $bl->update(['returned_qty' => round(max(0, (float) $bl->returned_qty - $qty), 4)]);
                    }
                }
            }

            $tax = round((float) $return->tax_amount, 4);
            $total = round($subtotal + $tax, 4);

            $lines = [];
            $lines[] = new LedgerLine(accountId: $payable->id, credit: $total, description: "Debit note {$return->number} reversed", partyType: $return->supplier->getMorphClass(), partyId: $return->supplier->id);
            foreach ($inventoryByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Goods restored');
                }
            }
            if ($tax > self::EPSILON) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'asset')->first();
                $lines[] = LedgerLine::debit($taxAccount->id, $tax, 'Input tax restored');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date, lines: $lines, type: 'purchase_return_reversal', reference: $return->number,
                memo: "Reversal of purchase return {$return->number}", source: $return,
            ));
            StockLedgerEntry::whereIn('id', $sleIds)->update(['journal_id' => $journal->id]);

            $return->update(['status' => 'reversed', 'reversal_journal_id' => $journal->id, 'reversed_at' => now()]);

            return $return;
        });
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'purchase_return'],
            ['prefix' => 'DRN-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('purchase_return');
    }
}
