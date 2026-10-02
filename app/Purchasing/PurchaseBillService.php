<?php

namespace App\Purchasing;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\PurchaseBill;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Posts a supplier bill. Item lines clear the GRNI accrual, charge lines hit
 * their expense account, input tax is recognised, and Accounts Payable is
 * credited against the supplier's subledger.
 *
 *   Dr Goods Received Not Invoiced (goods subtotal)
 *   Dr <charge accounts>            (freight, etc.)
 *   Dr Input Tax Receivable         (tax)
 *       Cr Accounts Payable         (total, party = supplier)
 */
class PurchaseBillService
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(PurchaseBill $bill): PurchaseBill
    {
        if ($bill->isPosted()) {
            throw new PurchasingException('This bill is already posted.');
        }

        $bill->load('lines', 'supplier');
        if ($bill->lines->isEmpty()) {
            throw new PurchasingException('Add at least one line before posting.');
        }

        $payable = $bill->supplier->payable_account_id
            ? Account::find($bill->supplier->payable_account_id)
            : Account::where('control_type', 'ap')->first();
        if (! $payable) {
            throw new PurchasingException('No Accounts Payable control account is configured.');
        }

        return DB::transaction(function () use ($bill, $payable) {
            // Foreign-currency bills carry AP, input tax and expense charges at the bill's
            // fx_rate (base per unit). The GRNI accrual was booked in base at receipt time,
            // so its clearing line stays in base — any residual is a purchase/FX variance,
            // consistent with how base-currency price differences already settle in GRNI.
            $baseCurrency = $this->tenant->get()->base_currency;
            $currency = $bill->currency ?: $baseCurrency;
            $rate = $currency === $baseCurrency ? 1.0 : ((float) $bill->fx_rate ?: 1.0);

            $grniAmount = 0.0;
            $charges = [];

            foreach ($bill->lines as $line) {
                $amount = round((float) $line->amount, 4);
                if ($line->item_id) {
                    $grniAmount = round($grniAmount + $amount, 4);
                } elseif ($line->account_id) {
                    $charges[$line->account_id] = round(($charges[$line->account_id] ?? 0) + $amount, 4);
                } else {
                    throw new PurchasingException('Each bill line needs either an item or an account.');
                }
            }

            $subtotal = round($grniAmount + array_sum($charges), 4);
            $tax = round((float) $bill->tax_amount, 4);
            $total = round($subtotal + $tax, 4);

            $lines = [];
            if ($grniAmount > 0.005) {
                $grni = Account::where('control_type', 'grni')->first();
                if (! $grni) {
                    throw new PurchasingException('No GRNI control account is configured.');
                }
                // Clear the base accrual: convert the goods portion at the bill rate.
                $lines[] = LedgerLine::debit($grni->id, round($grniAmount * $rate, 4), 'Clear goods received');
            }
            foreach ($charges as $accountId => $amount) {
                if ($amount > 0.005) {
                    $lines[] = new LedgerLine((int) $accountId, debit: $amount, description: 'Purchase charge', currency: $currency, fxRate: $rate);
                }
            }
            if ($tax > 0.005) {
                $taxAccount = Account::where('control_type', 'tax')->where('type', 'asset')->first();
                if (! $taxAccount) {
                    throw new PurchasingException('No input-tax control account is configured.');
                }
                $lines[] = new LedgerLine($taxAccount->id, debit: $tax, description: 'Input tax', currency: $currency, fxRate: $rate);
            }

            $lines[] = new LedgerLine(
                accountId: $payable->id,
                credit: $total,
                description: "Bill {$bill->supplier_invoice_no}",
                currency: $currency,
                fxRate: $rate,
                partyType: $bill->supplier->getMorphClass(),
                partyId: $bill->supplier->id,
            );

            $number = $this->allocateNumber();

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $bill->bill_date->toDateString(),
                lines: $lines,
                type: 'purchase_bill',
                reference: $number,
                memo: $bill->memo ?: "Purchase bill {$number}",
                source: $bill,
            ));

            $bill->update([
                'number' => $number,
                'status' => 'posted',
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            if ($bill->goods_receipt_id) {
                $bill->goodsReceipt()->update(['billed' => true]);
            }

            return $bill;
        });
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'purchase_bill'],
            ['prefix' => 'BILL-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('purchase_bill');
    }
}
