<?php

namespace App\Sales;

use App\Inventory\InventoryService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\SalesInvoice;
use App\Models\SalesOrderLine;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Posts a sales invoice. It relieves stock at cost through the valuation engine
 * (COGS) and books revenue, output tax and Accounts Receivable in one balanced
 * journal:
 *
 *   Dr Accounts Receivable  (total, party = customer)
 *       Cr Sales Revenue     (subtotal, per item income account)
 *       Cr Output Tax        (tax)
 *   Dr Cost of Goods Sold   (cost)
 *       Cr Inventory         (cost, per item inventory account)
 */
class SalesInvoiceService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(SalesInvoice $invoice): SalesInvoice
    {
        if ($invoice->isPosted()) {
            throw new SalesException('This invoice is already posted.');
        }

        $invoice->load('lines.item', 'customer', 'warehouse');
        if ($invoice->lines->isEmpty()) {
            throw new SalesException('Add at least one line before posting.');
        }

        $receivable = $invoice->customer->receivable_account_id
            ? Account::find($invoice->customer->receivable_account_id)
            : Account::where('control_type', 'ar')->first();
        if (! $receivable) {
            throw new SalesException('No Accounts Receivable control account is configured.');
        }

        return DB::transaction(function () use ($invoice, $receivable) {
            $date = $invoice->invoice_date->toDateString();
            $number = $this->allocateNumber();

            // The document may be transacted in a foreign currency. Revenue, output tax
            // and the AR balance are carried in that currency at fx_rate (base per unit);
            // stock cost, COGS and inventory always stay in the base ledger currency.
            $baseCurrency = $this->tenant->get()->base_currency;
            $currency = $invoice->currency ?: $baseCurrency;
            $rate = $currency === $baseCurrency ? 1.0 : ((float) $invoice->fx_rate ?: 1.0);

            $revenueByAccount = [];
            $cogsByAccount = [];
            $inventoryByAccount = [];
            $subtotal = 0.0;
            $cogsTotal = 0.0;

            foreach ($invoice->lines as $line) {
                $item = $line->item;
                if (! $item->inventory_account_id) {
                    throw new SalesException("Item {$item->code} has no inventory account mapped.");
                }

                // Relieve stock at valued cost.
                $sle = $this->inventory->issue($item, $invoice->warehouse, (float) $line->quantity, [
                    'posting_date' => $date,
                    'entry_type' => 'issue',
                    'source' => $invoice,
                    'voucher_no' => $number,
                    'remarks' => $line->description,
                ]);
                $cost = abs((float) $sle->value);
                $cogsTotal = round($cogsTotal + $cost, 4);
                // Snapshot the relieved cost on the line so a later credit note can reverse
                // COGS and re-stock at exactly the cost that left.
                $line->update(['cost' => round($cost, 4)]);

                $revenueAccount = $item->income_account_id ?: optional(Account::where('code', '4100')->first())->id;
                $cogsAccount = $item->cogs_account_id ?: optional(Account::where('code', '5100')->first())->id;
                if (! $revenueAccount || ! $cogsAccount) {
                    throw new SalesException("Item {$item->code} needs revenue and COGS accounts (or defaults 4100/5100).");
                }

                $amount = round((float) $line->amount, 4);
                $subtotal = round($subtotal + $amount, 4);
                $revenueByAccount[$revenueAccount] = round(($revenueByAccount[$revenueAccount] ?? 0) + $amount, 4);
                $cogsByAccount[$cogsAccount] = round(($cogsByAccount[$cogsAccount] ?? 0) + $cost, 4);
                $inventoryByAccount[$item->inventory_account_id] = round(($inventoryByAccount[$item->inventory_account_id] ?? 0) + $cost, 4);

                if ($line->sales_order_line_id) {
                    SalesOrderLine::whereKey($line->sales_order_line_id)->increment('delivered_qty', (float) $line->quantity);
                }
            }

            $tax = round((float) $invoice->tax_amount, 4);
            $total = round($subtotal + $tax, 4);

            $lines = [];
            $lines[] = new LedgerLine(
                accountId: $receivable->id,
                debit: $total,
                description: "Invoice {$number}",
                currency: $currency,
                fxRate: $rate,
                partyType: $invoice->customer->getMorphClass(),
                partyId: $invoice->customer->id,
            );
            foreach ($revenueByAccount as $accountId => $amount) {
                if ($amount > 0.005) {
                    $lines[] = new LedgerLine((int) $accountId, credit: $amount, description: 'Sales revenue', currency: $currency, fxRate: $rate);
                }
            }
            if ($tax > 0.005) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'liability')->first();
                if (! $taxAccount) {
                    throw new SalesException('No output-tax control account is configured.');
                }
                $lines[] = new LedgerLine($taxAccount->id, credit: $tax, description: 'Output tax', currency: $currency, fxRate: $rate);
            }
            foreach ($cogsByAccount as $accountId => $amount) {
                if ($amount > 0.005) {
                    $lines[] = LedgerLine::debit((int) $accountId, $amount, 'Cost of goods sold');
                }
            }
            foreach ($inventoryByAccount as $accountId => $amount) {
                if ($amount > 0.005) {
                    $lines[] = LedgerLine::credit((int) $accountId, $amount, 'Inventory relieved');
                }
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'sales_invoice',
                reference: $number,
                memo: $invoice->memo ?: "Sales invoice {$number}",
                source: $invoice,
            ));

            $this->inventory->stampJournal($invoice, $journal->id);

            $invoice->update([
                'number' => $number,
                'status' => 'posted',
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'cogs_total' => $cogsTotal,
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            $this->syncSalesOrderStatus($invoice);

            return $invoice;
        });
    }

    private function syncSalesOrderStatus(SalesInvoice $invoice): void
    {
        if (! $invoice->sales_order_id) {
            return;
        }
        $order = $invoice->salesOrder()->with('lines')->first();
        if (! $order) {
            return;
        }
        $delivered = $order->lines->every(fn ($l) => (float) $l->delivered_qty >= (float) $l->quantity - 1e-9);
        $order->update(['status' => $delivered ? 'delivered' : 'confirmed']);
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'sales_invoice'],
            ['prefix' => 'INV-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('sales_invoice');
    }
}
