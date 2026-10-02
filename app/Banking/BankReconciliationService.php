<?php

namespace App\Banking;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\JournalLine;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a general-ledger bank/cash account against a bank statement.
 *
 * Matching (clearing lines) never touches the ledger — it only records which posted
 * journal lines have appeared on the statement. Adjustments (bank charges, interest)
 * post one balanced journal through the PostingEngine and auto-clear their own bank leg.
 */
class BankReconciliationService
{
    private const EPSILON = 1e-6;

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /**
     * Open a new draft reconciliation for a bank account and statement date.
     */
    public function start(int $bankAccountId, string $statementDate, float $statementBalance): BankReconciliation
    {
        $account = Account::find($bankAccountId);
        if (! $account || ! $account->isPostable() || ! in_array($account->control_type, ['bank', 'cash'], true)) {
            throw new BankReconciliationException('Choose a valid, active bank or cash account.');
        }

        // Normalise the date so string comparison and storage never see a non-padded
        // value like "2026-1-5", which would sort wrong against "2026-09-30".
        $statementDate = Carbon::parse($statementDate)->toDateString();

        return DB::transaction(function () use ($account, $bankAccountId, $statementDate, $statementBalance) {
            // Serialise concurrent starts for the same account on the account row so two
            // requests cannot both create a draft or both slip past the ordering guard.
            Account::whereKey($account->id)->lockForUpdate()->first();

            if (BankReconciliation::where('bank_account_id', $bankAccountId)->where('status', 'draft')->exists()) {
                throw new BankReconciliationException('A reconciliation for this account is already open. Finish or discard it first.');
            }

            $previous = BankReconciliation::where('bank_account_id', $bankAccountId)
                ->where('status', 'completed')
                ->orderByDesc('statement_date')->orderByDesc('id')
                ->first();

            if ($previous && $statementDate <= $previous->statement_date->toDateString()) {
                throw new BankReconciliationException('The statement date must be after the last completed reconciliation ('.$previous->statement_date->toDateString().').');
            }

            return BankReconciliation::create([
                'company_id' => $this->tenant->id(),
                'bank_account_id' => $bankAccountId,
                'statement_date' => $statementDate,
                'opening_balance' => $previous ? (float) $previous->statement_balance : 0.0,
                'statement_balance' => round($statementBalance, 4),
                'status' => 'draft',
                'created_by' => optional(auth()->user())->id,
            ]);
        });
    }

    /**
     * Toggle whether a posted bank journal line is cleared in this reconciliation.
     */
    public function toggleCleared(BankReconciliation $reconciliation, int $journalLineId): void
    {
        DB::transaction(function () use ($reconciliation, $journalLineId) {
            $recon = BankReconciliation::whereKey($reconciliation->id)->lockForUpdate()->first();
            if (! $recon || ! $recon->isDraft()) {
                throw new BankReconciliationException('This reconciliation is not open for editing.');
            }

            $existing = $recon->lines()->where('journal_line_id', $journalLineId)->first();
            if ($existing) {
                $existing->delete();

                return;
            }

            $line = JournalLine::whereKey($journalLineId)
                ->where('account_id', $recon->bank_account_id)
                ->whereHas('journal', fn ($q) => $q->where('status', 'posted'))
                ->first();
            if (! $line) {
                throw new BankReconciliationException('That entry is not a posted line on this bank account.');
            }

            // Guard against clearing a line already reconciled elsewhere (the DB unique
            // index on journal_line_id is the real backstop; this is the friendly error).
            $reconciledElsewhere = BankReconciliationLine::where('journal_line_id', $journalLineId)
                ->where('bank_reconciliation_id', '!=', $recon->id)
                ->exists();
            if ($reconciledElsewhere) {
                throw new BankReconciliationException('That entry has already been reconciled in another statement.');
            }

            $recon->lines()->create([
                'company_id' => $this->tenant->id(),
                'journal_line_id' => $line->id,
                'amount' => round((float) $line->base_debit - (float) $line->base_credit, 4),
            ]);
        });
    }

    /**
     * Post a statement-only adjustment (bank charge or interest/credit) and clear it.
     *
     * @param  string  $kind  'charge' (money leaves the bank) or 'credit' (money enters)
     */
    public function addAdjustment(BankReconciliation $reconciliation, string $kind, float $amount, int $counterAccountId, string $date, ?string $memo = null): void
    {
        if (! in_array($kind, ['charge', 'credit'], true)) {
            throw new BankReconciliationException('Unknown adjustment type.');
        }
        $amount = round($amount, 4);
        if ($amount <= self::EPSILON) {
            throw new BankReconciliationException('The adjustment amount must be positive.');
        }

        $counter = Account::find($counterAccountId);
        if (! $counter || ! $counter->isPostable()) {
            throw new BankReconciliationException('Choose a valid contra account for the adjustment.');
        }
        if ((int) $counterAccountId === (int) $reconciliation->bank_account_id) {
            throw new BankReconciliationException('The contra account must differ from the bank account.');
        }

        DB::transaction(function () use ($reconciliation, $kind, $amount, $counter, $date, $memo) {
            $recon = BankReconciliation::whereKey($reconciliation->id)->lockForUpdate()->first();
            if (! $recon || ! $recon->isDraft()) {
                throw new BankReconciliationException('This reconciliation is not open for editing.');
            }

            $label = $memo ?: ($kind === 'charge' ? 'Bank charges' : 'Bank credit');
            $bankId = (int) $recon->bank_account_id;

            $lines = $kind === 'charge'
                ? [LedgerLine::debit($counter->id, $amount, $label), LedgerLine::credit($bankId, $amount, $label)]
                : [LedgerLine::debit($bankId, $amount, $label), LedgerLine::credit($counter->id, $amount, $label)];

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'bank_adjustment',
                reference: 'REC-'.$recon->id,
                memo: $label,
                source: $recon,
            ));

            // Auto-clear the bank leg of the adjustment we just posted.
            $bankLine = $journal->lines->firstWhere('account_id', $bankId);
            $recon->lines()->create([
                'company_id' => $this->tenant->id(),
                'journal_line_id' => $bankLine->id,
                'amount' => round((float) $bankLine->base_debit - (float) $bankLine->base_credit, 4),
            ]);
        });
    }

    /**
     * Finalise the reconciliation once it ties out exactly to the statement balance.
     */
    public function complete(BankReconciliation $reconciliation): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation) {
            $recon = BankReconciliation::whereKey($reconciliation->id)->lockForUpdate()->first();
            if (! $recon) {
                throw new BankReconciliationException('Reconciliation not found.');
            }
            if (! $recon->isDraft()) {
                throw new BankReconciliationException('Only a draft reconciliation can be completed.');
            }

            $recon->load('lines');
            if (abs($recon->difference()) > self::EPSILON) {
                throw new BankReconciliationException('The reconciliation is out of balance by '.number_format($recon->difference(), 2).'. Clear or adjust items until the difference is zero.');
            }

            $recon->update(['status' => 'completed', 'completed_at' => now()]);

            return $recon;
        });
    }

    /**
     * Reopen the most recent completed reconciliation for editing.
     */
    public function reopen(BankReconciliation $reconciliation): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation) {
            $recon = BankReconciliation::whereKey($reconciliation->id)->lockForUpdate()->first();
            if (! $recon || ! $recon->isCompleted()) {
                throw new BankReconciliationException('Only a completed reconciliation can be reopened.');
            }

            // A later reconciliation's opening balance was carried from this one; reopening
            // it would corrupt that chain, so only the latest may be reopened.
            $later = BankReconciliation::where('bank_account_id', $recon->bank_account_id)
                ->where('id', '!=', $recon->id)
                ->where('statement_date', '>=', $recon->statement_date)
                ->exists();
            if ($later) {
                throw new BankReconciliationException('A newer reconciliation exists for this account. Reopen that one first.');
            }

            $recon->update(['status' => 'draft', 'completed_at' => null]);

            return $recon;
        });
    }
}
