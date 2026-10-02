<?php

namespace App\Payroll;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Disburses a posted payroll run. It relieves each employee's Wages & Salaries
 * Payable subledger and credits the paying cash/bank account:
 *
 *   Dr Wages & Salaries Payable  (per employee subledger, net outstanding)
 *       Cr Cash / Bank
 *
 * Payslips accumulate paid_amount; the run moves to partially_paid or paid.
 */
class PayrollPaymentService
{
    private const EPSILON = 1e-9;

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /** Statuses from which a run may still be disbursed. Excludes draft, paid and reversed. */
    private const PAYABLE_STATUSES = ['posted', 'partially_paid'];

    public function pay(PayrollRun $run, int $paidFromAccountId, string $paymentDate, ?string $memo = null): PayrollPayment
    {
        if ($run->status === 'draft') {
            throw new HrException('Post the payroll run before recording a payment.');
        }
        if ($run->isReversed()) {
            throw new HrException('This payroll run has been reversed and cannot be paid.');
        }
        if (! in_array($run->status, self::PAYABLE_STATUSES, true)) {
            throw new HrException('This payroll run is not in a payable state.');
        }

        $run->load('payslips.employee');

        $paidFrom = Account::find($paidFromAccountId);
        if (! $paidFrom) {
            throw new HrException('The paying account does not exist.');
        }
        if (! $paidFrom->isPostable()) {
            throw new HrException("Account {$paidFrom->code} cannot be paid from.");
        }
        if (! in_array($paidFrom->control_type, ['cash', 'bank'], true)) {
            throw new HrException("Account {$paidFrom->code} — {$paidFrom->name} is not a cash or bank account.");
        }

        $wagesPayable = Account::where('control_type', 'wages_payable')->first();
        if (! $wagesPayable) {
            throw new HrException('No Wages & Salaries Payable control account is configured.');
        }

        $payable = $run->payslips->filter(fn ($slip) => $slip->outstanding() > self::EPSILON);
        if ($payable->isEmpty()) {
            throw new HrException('This run has no outstanding net pay to disburse.');
        }

        return DB::transaction(function () use ($run, $paidFrom, $wagesPayable, $payable, $paymentDate, $memo) {
            // Serialise concurrent disbursements: re-assert the payable state on the
            // locked row so a double-submit cannot post two payments for one run.
            $locked = PayrollRun::whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, self::PAYABLE_STATUSES, true)) {
                throw new HrException('This payroll run is not in a payable state.');
            }

            $number = $this->allocateNumber();

            $payment = PayrollPayment::create([
                'company_id' => $this->tenant->id(),
                'payroll_run_id' => $run->id,
                'paid_from_account_id' => $paidFrom->id,
                'number' => $number,
                'payment_date' => $paymentDate,
                'amount' => 0,
                'status' => 'draft',
                'memo' => $memo,
                'created_by' => optional(auth()->user())->id,
            ]);

            $lines = [];
            $total = 0.0;

            foreach ($payable as $slip) {
                $amount = $slip->outstanding();

                $payment->lines()->create([
                    'company_id' => $this->tenant->id(),
                    'payslip_id' => $slip->id,
                    'employee_id' => $slip->employee_id,
                    'amount' => $amount,
                ]);

                $lines[] = new LedgerLine(
                    accountId: $wagesPayable->id,
                    debit: $amount,
                    description: "Salary paid — {$slip->employee->name}",
                    partyType: $slip->employee->getMorphClass(),
                    partyId: $slip->employee_id,
                );

                $slip->update([
                    'paid_amount' => round((float) $slip->paid_amount + $amount, 4),
                    'status' => 'paid',
                ]);

                $total = round($total + $amount, 4);
            }

            $lines[] = LedgerLine::credit($paidFrom->id, $total, "Payroll disbursement {$number}");

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $paymentDate,
                lines: $lines,
                type: 'payroll_payment',
                reference: $number,
                memo: $memo ?: "Payroll payment {$number}",
                source: $payment,
            ));

            $payment->update([
                'amount' => $total,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            // Completion is defined by the outstanding balance, not the payslip status:
            // a zero-net payslip is never disbursed yet leaves nothing owed, so it must not
            // hold the run in 'partially_paid' forever.
            $fullyPaid = $run->load('payslips')->payslips->every(fn ($slip) => $slip->outstanding() <= self::EPSILON);
            $run->update(['status' => $fullyPaid ? 'paid' : 'partially_paid']);

            return $payment->load('lines', 'journal');
        });
    }

    /**
     * Reverse a posted disbursement: post a mirror journal, restore each payslip's
     * paid_amount, and move the run back to posted/partially_paid to reflect the
     * outstanding balance that has reappeared.
     */
    public function reverse(PayrollPayment $payment): PayrollPayment
    {
        if ($payment->status !== 'posted') {
            throw new HrException('Only a posted payroll payment can be reversed.');
        }
        if (! $payment->journal_id) {
            throw new HrException('This payment has no journal to reverse.');
        }

        return DB::transaction(function () use ($payment) {
            // Serialise concurrent reversals: the loser sees 'reversed' on the locked
            // row and aborts, so the mirror journal and paid_amount are touched once.
            $locked = PayrollPayment::whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new HrException('This payment has already been reversed.');
            }

            $payment->load('lines.payslip', 'payrollRun');

            $reversal = $this->posting->reverse($payment->journal, now()->toDateString());

            foreach ($payment->lines as $line) {
                $slip = $line->payslip;
                if (! $slip) {
                    continue;
                }
                $slip->update([
                    'paid_amount' => round((float) $slip->paid_amount - (float) $line->amount, 4),
                    'status' => 'posted',
                ]);
            }

            $payment->update([
                'status' => 'reversed',
                'reversal_journal_id' => $reversal->id,
                'reversed_at' => now(),
            ]);

            $run = $payment->payrollRun;
            $run->load('payslips');
            $fullyPaid = $run->payslips->every(fn ($slip) => $slip->outstanding() <= self::EPSILON);
            $anyPaid = $run->payslips->contains(fn ($slip) => (float) $slip->paid_amount > self::EPSILON);
            $run->update(['status' => $fullyPaid ? 'paid' : ($anyPaid ? 'partially_paid' : 'posted')]);

            return $payment->load('lines', 'journal', 'reversalJournal');
        });
    }

    private function allocateNumber(): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => 'payroll_payment'],
            ['prefix' => 'PP-', 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next('payroll_payment');
    }
}
