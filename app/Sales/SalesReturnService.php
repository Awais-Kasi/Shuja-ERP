<?php

namespace App\Sales;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\StockBalance;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Posts a sales return (credit note) — the mirror of a sales invoice: goods come back
 * into stock at their original cost, revenue/output-tax/COGS are reversed, and the
 * customer's receivable is reduced.
 *
 *   Dr Sales Revenue     (subtotal)      Dr Cost of Goods Sold reversal → Cr COGS (cost)
 *   Dr Output Tax        (tax)           Dr Inventory (cost)
 *       Cr Accounts Receivable (total, party = customer)
 */
class SalesReturnService
{
    private const EPSILON = 1e-4;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(SalesReturn $return): SalesReturn
    {
        if ($return->status !== 'draft') {
            throw new SalesException('This return is already posted.');
        }

        $return->load('lines.item', 'lines.invoiceLine', 'customer', 'warehouse');
        if ($return->lines->isEmpty()) {
            throw new SalesException('Add at least one line before posting.');
        }

        $receivable = $return->customer->receivable_account_id
            ? Account::find($return->customer->receivable_account_id)
            : Account::where('control_type', 'ar')->first();
        if (! $receivable) {
            throw new SalesException('No Accounts Receivable control account is configured.');
        }

        return DB::transaction(function () use ($return, $receivable) {
            $date = $return->return_date->toDateString();
            $number = $this->allocateNumber();

            $revenueByAccount = [];
            $cogsByAccount = [];
            $inventoryByAccount = [];
            $subtotal = 0.0;
            $cogsTotal = 0.0;

            foreach ($return->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    throw new SalesException('Return quantities must be positive.');
                }
                if (! $item->inventory_account_id) {
                    throw new SalesException("Item {$item->code} has no inventory account mapped.");
                }

                // Cap against the invoice line and record the return.
                $unitCost = $this->unitCost($line, $item, $return->warehouse_id);
                if ($line->sales_invoice_line_id) {
                    $il = SalesInvoiceLine::whereKey($line->sales_invoice_line_id)->lockForUpdate()->first();
                    if ($il) {
                        $remaining = round((float) $il->quantity - (float) $il->returned_qty, 4);
                        if ($qty - $remaining > self::EPSILON) {
                            throw new SalesException("Cannot return {$qty} of {$item->code}: only {$remaining} remain on the invoice.");
                        }
                        $il->update(['returned_qty' => round((float) $il->returned_qty + $qty, 4)]);
                    }
                }

                $cost = round($qty * $unitCost, 4);
                $this->inventory->receive($item, $return->warehouse, $qty, $unitCost, [
                    'posting_date' => $date, 'entry_type' => 'receipt', 'source' => $return,
                    'voucher_no' => $number, 'remarks' => 'Sales return',
                ]);

                $amount = round($qty * (float) $line->rate, 4);
                $line->update(['amount' => $amount, 'cost' => $cost]);
                $subtotal = round($subtotal + $amount, 4);
                $cogsTotal = round($cogsTotal + $cost, 4);

                $revenueAccount = $item->income_account_id ?: optional(Account::where('code', '4100')->first())->id;
                $cogsAccount = $item->cogs_account_id ?: optional(Account::where('code', '5100')->first())->id;
                if (! $revenueAccount || ! $cogsAccount) {
                    throw new SalesException("Item {$item->code} needs revenue and COGS accounts.");
                }
                $revenueByAccount[$revenueAccount] = round(($revenueByAccount[$revenueAccount] ?? 0) + $amount, 4);
                $cogsByAccount[$cogsAccount] = round(($cogsByAccount[$cogsAccount] ?? 0) + $cost, 4);
                $inventoryByAccount[$item->inventory_account_id] = round(($inventoryByAccount[$item->inventory_account_id] ?? 0) + $cost, 4);
            }

            $tax = round((float) $return->tax_amount, 4);
            $total = round($subtotal + $tax, 4);

            $lines = [];
            foreach ($revenueByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Sales return');
                }
            }
            if ($tax > self::EPSILON) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'liability')->first();
                if (! $taxAccount) {
                    throw new SalesException('No output-tax control account is configured.');
                }
                $lines[] = LedgerLine::debit($taxAccount->id, $tax, 'Output tax reversed');
            }
            $lines[] = new LedgerLine(accountId: $receivable->id, credit: $total, description: "Credit note {$number}", partyType: $return->customer->getMorphClass(), partyId: $return->customer->id);
            foreach ($inventoryByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Inventory returned');
                }
            }
            foreach ($cogsByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'COGS reversed');
                }
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date, lines: $lines, type: 'sales_return', reference: $number,
                memo: $return->memo ?: "Sales return {$number}", source: $return,
            ));
            $this->inventory->stampJournal($return, $journal->id);

            $return->update([
                'number' => $number, 'status' => 'posted', 'subtotal' => $subtotal,
                'tax_amount' => $tax, 'total' => $total, 'cogs_total' => $cogsTotal,
                'journal_id' => $journal->id, 'posted_at' => now(),
            ]);

            return $return;
        });
    }

    public function reverse(SalesReturn $return): SalesReturn
    {
        if ($return->isReversed()) {
            throw new SalesException('This return is already reversed.');
        }
        if (! $return->isPosted()) {
            throw new SalesException('Only a posted return can be reversed.');
        }

        $return->load('lines.item', 'lines.invoiceLine', 'customer', 'warehouse');

        return DB::transaction(function () use ($return) {
            $locked = SalesReturn::whereKey($return->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new SalesException('This return can no longer be reversed.');
            }

            $receivable = $return->customer->receivable_account_id
                ? Account::find($return->customer->receivable_account_id)
                : Account::where('control_type', 'ar')->first();

            $date = now()->toDateString();
            $sleIds = [];
            $revenueByAccount = [];
            $cogsByAccount = [];
            $inventoryByAccount = [];
            $subtotal = 0.0;

            foreach ($return->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->quantity;

                // Take the restocked goods back out at current cost.
                $out = $this->inventory->issue($item, $return->warehouse, $qty, [
                    'posting_date' => $date, 'entry_type' => 'issue', 'source' => $return,
                    'voucher_no' => $return->number, 'remarks' => 'Sales return reversal',
                ]);
                $sleIds[] = $out->id;
                $cost = abs((float) $out->value);
                $amount = round((float) $line->amount, 4);
                $subtotal = round($subtotal + $amount, 4);

                $revenueAccount = $item->income_account_id ?: optional(Account::where('code', '4100')->first())->id;
                $cogsAccount = $item->cogs_account_id ?: optional(Account::where('code', '5100')->first())->id;
                $revenueByAccount[$revenueAccount] = round(($revenueByAccount[$revenueAccount] ?? 0) + $amount, 4);
                $cogsByAccount[$cogsAccount] = round(($cogsByAccount[$cogsAccount] ?? 0) + $cost, 4);
                $inventoryByAccount[$item->inventory_account_id] = round(($inventoryByAccount[$item->inventory_account_id] ?? 0) + $cost, 4);

                if ($line->sales_invoice_line_id && $line->invoiceLine) {
                    $il = SalesInvoiceLine::whereKey($line->sales_invoice_line_id)->lockForUpdate()->first();
                    if ($il) {
                        $il->update(['returned_qty' => round(max(0, (float) $il->returned_qty - $qty), 4)]);
                    }
                }
            }

            $tax = round((float) $return->tax_amount, 4);
            $total = round($subtotal + $tax, 4);

            $lines = [];
            foreach ($revenueByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Sales return reversed');
                }
            }
            if ($tax > self::EPSILON) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'liability')->first();
                $lines[] = LedgerLine::credit($taxAccount->id, $tax, 'Output tax restored');
            }
            $lines[] = new LedgerLine(accountId: $receivable->id, debit: $total, description: "Credit note {$return->number} reversed", partyType: $return->customer->getMorphClass(), partyId: $return->customer->id);
            foreach ($inventoryByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Inventory relieved');
                }
            }
            foreach ($cogsByAccount as $accountId => $amount) {
                if ($amount > self::EPSILON) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'COGS restored');
                }
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date, lines: $lines, type: 'sales_return_reversal', reference: $return->number,
                memo: "Reversal of sales return {$return->number}", source: $return,
            ));
            \App\Models\StockLedgerEntry::whereIn('id', $sleIds)->update(['journal_id' => $journal->id]);

            $return->update(['status' => 'reversed', 'reversal_journal_id' => $journal->id, 'reversed_at' => now()]);

            return $return;
        });
    }

    /** Per-unit cost to restock at: the invoice line's snapshot, else current WAC, else the sale rate. */
    private function unitCost($line, $item, int $warehouseId): float
    {
        if ($line->sales_invoice_line_id && $line->invoiceLine && (float) $line->invoiceLine->quantity > 0 && (float) $line->invoiceLine->cost > 0) {
            return round((float) $line->invoiceLine->cost / (float) $line->invoiceLine->quantity, 6);
        }
        $wac = (float) (StockBalance::where('item_id', $item->id)->where('warehouse_id', $warehouseId)->first()?->averageRate() ?? 0);
        if ($wac > 0) {
            return round($wac, 6);
        }

        return round((float) $line->rate, 6);
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'sales_return'],
            ['prefix' => 'CRN-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('sales_return');
    }
}
