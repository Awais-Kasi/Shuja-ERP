<?php

namespace App\Periods;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Period-end controls: lock/reopen individual periods, and the year-end close that
 * sweeps every income-statement account into Retained Earnings via one balanced journal.
 */
class PeriodCloseService
{
    private const EPSILON = 1e-4;

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    public function lock(AccountingPeriod $period): void
    {
        if ($period->status === 'locked') {
            throw new PeriodCloseException('This period is already locked.');
        }
        $period->update(['status' => 'locked']);
    }

    public function reopen(AccountingPeriod $period): void
    {
        if ($period->status !== 'locked') {
            throw new PeriodCloseException('Only a locked period can be reopened.');
        }
        if ($period->fiscalYear && $period->fiscalYear->isClosed()) {
            throw new PeriodCloseException('Reopen the fiscal year before unlocking its periods.');
        }
        $period->update(['status' => 'open']);
    }

    /**
     * Close a fiscal year: zero every P&L account into Retained Earnings, then lock
     * all periods and mark the year closed. Posts one balanced "closing" journal.
     */
    public function closeYear(FiscalYear $year, ?string $date = null): FiscalYear
    {
        if ($year->isClosed()) {
            throw new PeriodCloseException('This fiscal year is already closed.');
        }

        $retained = Account::where('control_type', 'retained_earnings')->where('is_group', false)->first();
        if (! $retained || ! $retained->isPostable()) {
            throw new PeriodCloseException('No postable Retained Earnings account is configured.');
        }

        $date = $date ?: $year->ends_on->toDateString();
        if ($date < $year->starts_on->toDateString() || $date > $year->ends_on->toDateString()) {
            throw new PeriodCloseException('The closing date must fall within the fiscal year.');
        }

        // Balances of every P&L account within the year (base debit − credit).
        $plAccounts = Account::whereIn('type', ['income', 'expense'])->where('is_group', false)->pluck('id')->all();
        $balances = $plAccounts ? DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.company_id', $this->tenant->id())
            ->where('j.status', 'posted')
            ->whereIn('l.account_id', $plAccounts)
            ->whereDate('j.entry_date', '>=', $year->starts_on->toDateString())
            ->whereDate('j.entry_date', '<=', $year->ends_on->toDateString())
            ->groupBy('l.account_id')
            ->select('l.account_id', DB::raw('SUM(l.base_debit - l.base_credit) as bal'))
            ->pluck('bal', 'l.account_id') : collect();

        $lines = [];
        $net = 0.0; // Σ(debit − credit) across P&L = negative for a profit
        foreach ($balances as $accountId => $bal) {
            $b = round((float) $bal, 4);
            if (abs($b) <= self::EPSILON) {
                continue;
            }
            // Post the opposite side to flatten the account to zero.
            $lines[] = $b > 0
                ? LedgerLine::credit((int) $accountId, $b, 'Year-end close')
                : LedgerLine::debit((int) $accountId, -$b, 'Year-end close');
            $net = round($net + $b, 4);
        }

        if (! $lines) {
            throw new PeriodCloseException('There is nothing to close — no income or expense activity this year.');
        }

        // Balancing line to Retained Earnings: a loss (net > 0) debits it, a profit credits it.
        $lines[] = $net > 0
            ? LedgerLine::debit($retained->id, $net, 'Net loss to retained earnings')
            : LedgerLine::credit($retained->id, -$net, 'Net profit to retained earnings');

        return DB::transaction(function () use ($year, $lines, $date) {
            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'closing',
                reference: 'CLOSE-'.$year->name,
                memo: "Year-end closing for {$year->name}",
                source: $year,
            ));

            // Lock every period in the year and mark it closed.
            AccountingPeriod::where('fiscal_year_id', $year->id)->update(['status' => 'locked']);
            $year->update(['status' => 'closed', 'closing_journal_id' => $journal->id, 'closed_at' => now()]);

            return $year->refresh();
        });
    }

    /**
     * Reopen a closed year: unlock its periods, reverse the closing journal, mark open.
     */
    public function reopenYear(FiscalYear $year): FiscalYear
    {
        if (! $year->isClosed()) {
            throw new PeriodCloseException('This fiscal year is not closed.');
        }

        return DB::transaction(function () use ($year) {
            // Unlock first so the reversal can post into the closing period.
            AccountingPeriod::where('fiscal_year_id', $year->id)->update(['status' => 'open']);

            if ($year->closing_journal_id) {
                $closing = $year->closingJournal;
                if ($closing && $closing->isPosted()) {
                    $this->posting->reverse($closing, $year->ends_on->toDateString());
                }
            }

            $year->update(['status' => 'open', 'closing_journal_id' => null, 'closed_at' => null]);

            return $year->refresh();
        });
    }
}
