<?php

namespace App\Payments;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Payment;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Records customer receipts and supplier payments, settling posted invoices/bills.
 *
 * A receipt posts Dr Cash/Bank / Cr Accounts Receivable (party = customer); a payment
 * posts Dr Accounts Payable / Cr Cash/Bank (party = supplier). Allocations mark which
 * documents the money settles; any unallocated remainder sits on the party's control
 * account as an advance. Fully reversible.
 */
class PaymentService
{
    private const EPSILON = 1e-4;

    /** Foreign Exchange Gain/(Loss) — absorbs realised FX when a foreign balance settles. */
    private const FX_ACCOUNT_CODE = '4400';

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function post(Payment $payment): Payment
    {
        if ($payment->status !== 'draft') {
            throw new PaymentException('This payment has already been posted.');
        }

        $payment->load('allocations.allocatable', 'party', 'account');

        $receipt = $payment->isReceipt();
        $amount = round((float) $payment->amount, 4);
        if ($amount <= self::EPSILON) {
            throw new PaymentException('The amount must be positive.');
        }

        // The payment may be tendered in a foreign currency at payment-day fx_rate.
        $baseCurrency = $this->tenant->get()->base_currency;
        $currency = $payment->currency ?: $baseCurrency;
        $payRate = $currency === $baseCurrency ? 1.0 : ((float) $payment->fx_rate ?: 1.0);

        $cash = $payment->account;
        if (! $cash || ! $cash->isPostable() || ! in_array($cash->control_type, ['cash', 'bank'], true)) {
            throw new PaymentException('Choose a valid cash or bank account.');
        }

        $party = $payment->party;
        $expectedParty = $receipt ? Customer::class : Supplier::class;
        if (! $party || $party->getMorphClass() !== (new $expectedParty)->getMorphClass()) {
            throw new PaymentException($receipt ? 'Choose a customer for a receipt.' : 'Choose a supplier for a payment.');
        }

        $control = $this->controlAccount($payment, $party);

        $docClass = $receipt ? SalesInvoice::class : PurchaseBill::class;
        $allocTotal = 0.0;
        foreach ($payment->allocations as $alloc) {
            $doc = $alloc->allocatable;
            if (! $doc || $doc->getMorphClass() !== (new $docClass)->getMorphClass()) {
                throw new PaymentException('Allocations must target the right document type for this payment.');
            }
            if ((int) $doc->{$receipt ? 'customer_id' : 'supplier_id'} !== (int) $payment->party_id) {
                throw new PaymentException('You can only allocate to documents of the selected party.');
            }
            if (! $doc->isPosted()) {
                throw new PaymentException('Only posted documents can be settled.');
            }
            $a = round((float) $alloc->amount, 4);
            if ($a <= self::EPSILON) {
                throw new PaymentException('Allocation amounts must be positive.');
            }
            $allocTotal = round($allocTotal + $a, 4);
        }
        if ($allocTotal - $amount > self::EPSILON) {
            throw new PaymentException('Allocations ('.number_format($allocTotal, 2).') exceed the payment amount ('.number_format($amount, 2).').');
        }

        return DB::transaction(function () use ($payment, $receipt, $amount, $cash, $control, $party, $currency, $payRate, $baseCurrency) {
            $number = $this->allocateNumber($receipt);

            // Relieve each settled document at ITS original booking rate so the subledger
            // clears in both foreign and base; the cash always moves at the payment rate.
            // Whatever base gap that opens between the two is realised foreign exchange.
            $controlLegs = [];
            $allocForeign = 0.0;
            foreach ($payment->allocations as $alloc) {
                // Lock and re-check each document so two payments cannot over-settle it.
                $doc = $alloc->allocatable->newQuery()->whereKey($alloc->allocatable_id)->lockForUpdate()->first();
                $a = round((float) $alloc->amount, 4);
                $docCurrency = $doc->currency ?: $baseCurrency;
                if ($docCurrency !== $currency) {
                    throw new PaymentException("{$doc->number} is billed in {$docCurrency}; settle it with a {$docCurrency} payment.");
                }
                if ($a - $doc->outstanding() > self::EPSILON) {
                    throw new PaymentException("Allocation {$a} exceeds the {$doc->outstanding()} outstanding on {$doc->number}.");
                }
                $doc->update(['amount_paid' => round((float) $doc->amount_paid + $a, 4)]);
                $docRate = $currency === $baseCurrency ? 1.0 : ((float) $doc->fx_rate ?: 1.0);
                $controlLegs[] = ['amount' => $a, 'rate' => $docRate];
                $allocForeign = round($allocForeign + $a, 4);
            }

            // Any unapplied remainder is a fresh advance, carried at the payment rate.
            $advance = round($amount - $allocForeign, 4);
            if ($advance > self::EPSILON) {
                $controlLegs[] = ['amount' => $advance, 'rate' => $payRate];
            }

            $cashLine = new LedgerLine(
                accountId: $cash->id,
                debit: $receipt ? $amount : 0,
                credit: $receipt ? 0 : $amount,
                description: ($receipt ? 'Receipt ' : 'Payment ').$number,
                currency: $currency,
                fxRate: $payRate,
            );
            $controlLines = [];
            foreach ($controlLegs as $leg) {
                $controlLines[] = new LedgerLine(
                    accountId: $control->id,
                    debit: $receipt ? 0 : $leg['amount'],
                    credit: $receipt ? $leg['amount'] : 0,
                    description: ($receipt ? 'Receipt ' : 'Payment ').$number,
                    currency: $currency,
                    fxRate: $leg['rate'],
                    partyType: $party->getMorphClass(),
                    partyId: $party->id,
                );
            }
            $lines = $receipt
                ? array_merge([$cashLine], $controlLines)
                : array_merge($controlLines, [$cashLine]);

            // Square debits to credits in base — the plug is the realised FX gain/loss.
            $debitBase = 0.0;
            $creditBase = 0.0;
            foreach ($lines as $l) {
                $r = (float) $l->fxRate ?: 1.0;
                $debitBase = round($debitBase + round((float) $l->debit * $r, 4), 4);
                $creditBase = round($creditBase + round((float) $l->credit * $r, 4), 4);
            }
            $imbalance = round($debitBase - $creditBase, 4);
            if (abs($imbalance) > self::EPSILON) {
                $fx = Account::where('code', self::FX_ACCOUNT_CODE)->first();
                if (! $fx || ! $fx->isPostable()) {
                    throw new PaymentException('No postable Foreign Exchange Gain/(Loss) account ('.self::FX_ACCOUNT_CODE.') is configured.');
                }
                $lines[] = $imbalance > 0
                    ? LedgerLine::credit($fx->id, $imbalance, 'Realised FX gain')
                    : LedgerLine::debit($fx->id, -$imbalance, 'Realised FX loss');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $payment->payment_date->toDateString(),
                lines: $lines,
                type: $receipt ? 'receipt' : 'payment',
                reference: $payment->reference ?: $number,
                memo: $payment->memo ?: ($receipt ? "Customer receipt {$number}" : "Supplier payment {$number}"),
                source: $payment,
            ));

            $payment->update([
                'number' => $number,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            return $payment;
        });
    }

    public function reverse(Payment $payment): Payment
    {
        if ($payment->isReversed()) {
            throw new PaymentException('This payment is already reversed.');
        }
        if ($payment->status !== 'posted') {
            throw new PaymentException('Only a posted payment can be reversed.');
        }

        $payment->load('allocations.allocatable', 'journal');

        return DB::transaction(function () use ($payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new PaymentException('This payment can no longer be reversed.');
            }

            foreach ($payment->allocations as $alloc) {
                $doc = $alloc->allocatable->newQuery()->whereKey($alloc->allocatable_id)->lockForUpdate()->first();
                if ($doc) {
                    $doc->update(['amount_paid' => round(max(0, (float) $doc->amount_paid - (float) $alloc->amount), 4)]);
                }
            }

            $reversal = $this->posting->reverse($payment->journal, now()->toDateString());

            $payment->update([
                'status' => 'reversed',
                'reversal_journal_id' => $reversal->id,
                'reversed_at' => now(),
            ]);

            return $payment;
        });
    }

    private function controlAccount(Payment $payment, $party): Account
    {
        if ($payment->isReceipt()) {
            $account = $party->receivable_account_id ? Account::find($party->receivable_account_id) : Account::where('control_type', 'ar')->first();
            if (! $account) {
                throw new PaymentException('No Accounts Receivable control account is configured.');
            }
        } else {
            $account = $party->payable_account_id ? Account::find($party->payable_account_id) : Account::where('control_type', 'ap')->first();
            if (! $account) {
                throw new PaymentException('No Accounts Payable control account is configured.');
            }
        }

        return $account;
    }

    private function allocateNumber(bool $receipt): string
    {
        $key = $receipt ? 'receipt' : 'payment';
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => $key],
            ['prefix' => $receipt ? 'RCV-' : 'PAY-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next($key);
    }
}
