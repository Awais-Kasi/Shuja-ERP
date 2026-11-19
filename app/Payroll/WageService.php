<?php

namespace App\Payroll;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\NumberSequence;
use App\Models\WageEntry;
use App\Models\WagePayment;
use App\Models\WageWorker;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Daily-wage (dihari) workers paid per unit of work (e.g. per KG). Recording
 * work raises what we owe the worker; paying lowers it. Both post to the ledger
 * (Wages expense / Wages Payable stamped to the worker) so the worker's wallet
 * balance equals their payable and the wages show in Profit & Loss.
 */
class WageService
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /** Record work done — earned = quantity * rate. Books Dr Wages / Cr Wages Payable (worker). */
    public function recordWork(WageWorker $worker, array $data): WageEntry
    {
        $quantity = round((float) $data['quantity'], 4);
        $rate = round((float) $data['rate'], 4);
        if ($quantity <= 0) {
            throw new WageException('Quantity must be more than zero.');
        }
        if ($rate < 0) {
            throw new WageException('Rate cannot be negative.');
        }
        $amount = round($quantity * $rate, 4);

        $payable = $this->wagesPayableAccount();
        $expense = $this->wagesExpenseAccount();

        return DB::transaction(function () use ($worker, $data, $quantity, $rate, $amount, $payable, $expense) {
            $date = $data['entry_date'] ?? now()->toDateString();
            $number = $this->allocateNumber('wage_entry', 'WG-');

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: [
                    LedgerLine::debit($expense, $amount, "Wages — {$worker->name}"),
                    new LedgerLine(
                        accountId: $payable,
                        credit: $amount,
                        description: "Wages earned — {$worker->name}",
                        partyType: $worker->getMorphClass(),
                        partyId: $worker->id,
                    ),
                ],
                type: 'wage_entry',
                reference: $number,
                memo: ($data['description'] ?? null) ?: "Wages for {$worker->name}",
            ));

            return WageEntry::create([
                'company_id' => $worker->company_id,
                'wage_worker_id' => $worker->id,
                'number' => $number,
                'entry_date' => $date,
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => $amount,
                'description' => $data['description'] ?? null,
                'journal_id' => $journal->id,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /** Pay a worker (daily / weekly / on demand). Books Dr Wages Payable (worker) / Cr Cash|Bank. */
    public function pay(WageWorker $worker, array $data): WagePayment
    {
        $amount = round((float) $data['amount'], 4);
        if ($amount <= 0) {
            throw new WageException('Payment amount must be more than zero.');
        }

        $payable = $this->wagesPayableAccount();
        $credit = Account::find($data['account_id']);
        if (! $credit || ! in_array($credit->control_type, ['cash', 'bank'], true)) {
            throw new WageException('Pay from a cash or bank account.');
        }

        return DB::transaction(function () use ($worker, $data, $amount, $payable, $credit) {
            $date = $data['payment_date'] ?? now()->toDateString();
            $number = $this->allocateNumber('wage_payment', 'WP-');

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: [
                    new LedgerLine(
                        accountId: $payable,
                        debit: $amount,
                        description: "Wages paid — {$worker->name}",
                        partyType: $worker->getMorphClass(),
                        partyId: $worker->id,
                    ),
                    LedgerLine::credit($credit->id, $amount, "Wages paid — {$worker->name}"),
                ],
                type: 'wage_payment',
                reference: $number,
                memo: ($data['reference'] ?? null) ?: "Wage payment to {$worker->name}",
            ));

            return WagePayment::create([
                'company_id' => $worker->company_id,
                'wage_worker_id' => $worker->id,
                'number' => $number,
                'payment_date' => $date,
                'amount' => $amount,
                'account_id' => $credit->id,
                'reference' => $data['reference'] ?? null,
                'journal_id' => $journal->id,
                'created_by' => Auth::id(),
            ]);
        });
    }

    private function wagesPayableAccount(): int
    {
        $id = Account::where('control_type', 'wages_payable')->value('id');
        if (! $id) {
            throw new WageException('No "Wages & Salaries Payable" control account is configured.');
        }

        return (int) $id;
    }

    private function wagesExpenseAccount(): int
    {
        $id = Account::where('code', '6100')->value('id')
            ?? Account::where('type', 'expense')->where('is_group', false)->orderBy('code')->value('id');
        if (! $id) {
            throw new WageException('No wages expense account is configured.');
        }

        return (int) $id;
    }

    private function allocateNumber(string $key, string $prefix): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => $key],
            ['prefix' => $prefix, 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next($key);
    }
}
