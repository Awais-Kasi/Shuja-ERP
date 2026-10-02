<?php

namespace Tests\Feature;

use App\Banking\BankReconciliationException;
use App\Banking\BankReconciliationService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = Carbon::now()->toDateString();

        $this->company = Company::create(['name' => 'Bank Co', 'code' => 'BNK', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);

        foreach ([
            ['1102', 'Bank', 'asset', 'bank'], ['1101', 'Cash', 'asset', 'cash'],
            ['3100', 'Capital', 'equity', null], ['6700', 'Bank Charges', 'expense', null], ['4300', 'Interest Income', 'income', null],
            ['6500', 'Other Payments', 'expense', null],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    /** Post a bank movement and return the id of the bank leg's journal line. */
    private function postBank(float $signed): int
    {
        $bank = $this->acct('1102');
        $amount = abs($signed);
        $lines = $signed >= 0
            ? [LedgerLine::debit($bank, $amount, 'Deposit'), LedgerLine::credit($this->acct('3100'), $amount, 'Capital')]
            : [LedgerLine::debit($this->acct('6500'), $amount, 'Payment'), LedgerLine::credit($bank, $amount, 'Payment')];

        $journal = app(PostingEngine::class)->post(new LedgerEntry(entryDate: $this->date, lines: $lines, type: 'manual'));

        return (int) JournalLine::where('journal_id', $journal->id)->where('account_id', $bank)->value('id');
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    public function test_start_opens_a_draft_with_zero_opening_when_no_prior(): void
    {
        $recon = app(BankReconciliationService::class)->start($this->acct('1102'), $this->date, 1000);

        $this->assertSame('draft', $recon->status);
        $this->assertEqualsWithDelta(0.0, (float) $recon->opening_balance, 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $recon->statement_balance, 0.001);
    }

    public function test_clearing_lines_moves_the_difference_and_allows_completion(): void
    {
        $deposit = $this->postBank(1000);   // +1000 on bank
        $this->postBank(-200);              // -200 outstanding, left uncleared

        $service = app(BankReconciliationService::class);
        $recon = $service->start($this->acct('1102'), $this->date, 1000); // statement shows only the deposit

        // Nothing cleared yet → difference is the full statement balance.
        $this->assertEqualsWithDelta(1000.0, $recon->refresh()->difference(), 0.001);

        $service->toggleCleared($recon, $deposit);
        $this->assertEqualsWithDelta(0.0, $recon->refresh()->load('lines')->difference(), 0.001);

        $service->complete($recon);
        $this->assertSame('completed', $recon->refresh()->status);
    }

    public function test_cannot_complete_when_out_of_balance(): void
    {
        $this->postBank(1000);
        $service = app(BankReconciliationService::class);
        $recon = $service->start($this->acct('1102'), $this->date, 1000);

        $this->expectException(BankReconciliationException::class);
        $service->complete($recon); // nothing cleared → difference 1000
    }

    public function test_toggle_is_idempotent_pair(): void
    {
        $deposit = $this->postBank(500);
        $service = app(BankReconciliationService::class);
        $recon = $service->start($this->acct('1102'), $this->date, 500);

        $service->toggleCleared($recon, $deposit);
        $this->assertSame(1, $recon->refresh()->lines()->count());
        $service->toggleCleared($recon, $deposit);
        $this->assertSame(0, $recon->refresh()->lines()->count());
    }

    public function test_charge_adjustment_posts_balanced_journal_and_clears_itself(): void
    {
        $deposit = $this->postBank(1000);
        $payment = $this->postBank(-200);
        $service = app(BankReconciliationService::class);

        // Statement: deposit +1000, payment -200, and a -50 bank charge not yet in books.
        $recon = $service->start($this->acct('1102'), $this->date, 750);
        $service->toggleCleared($recon, $deposit);
        $service->toggleCleared($recon, $payment);
        $this->assertEqualsWithDelta(-50.0, $recon->refresh()->load('lines')->difference(), 0.001);

        $service->addAdjustment($recon, 'charge', 50, $this->acct('6700'), $this->date, 'Monthly fee');

        $this->assertEqualsWithDelta(0.0, $recon->refresh()->load('lines')->difference(), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->gl('6700'), 0.001);   // expense debited
        $this->assertEqualsWithDelta(750.0, $this->gl('1102'), 0.001);  // 1000 - 200 - 50

        $service->complete($recon);
        $this->assertSame('completed', $recon->refresh()->status);
    }

    public function test_credit_adjustment_increases_bank_and_income(): void
    {
        $service = app(BankReconciliationService::class);
        $recon = $service->start($this->acct('1102'), $this->date, 30);
        $service->addAdjustment($recon, 'credit', 30, $this->acct('4300'), $this->date, 'Interest');

        $this->assertEqualsWithDelta(30.0, $this->gl('1102'), 0.001);
        $this->assertEqualsWithDelta(-30.0, $this->gl('4300'), 0.001); // income credited
        $this->assertEqualsWithDelta(0.0, $recon->refresh()->load('lines')->difference(), 0.001);
    }

    public function test_a_reconciled_line_cannot_be_cleared_in_another_statement(): void
    {
        $deposit = $this->postBank(1000);
        $service = app(BankReconciliationService::class);
        $first = $service->start($this->acct('1102'), $this->date, 1000);
        $service->toggleCleared($first, $deposit);
        $service->complete($first);

        $later = $service->start($this->acct('1102'), Carbon::now()->addMonth()->toDateString(), 1000);
        $this->expectException(BankReconciliationException::class);
        $service->toggleCleared($later, $deposit);
    }

    public function test_opening_balance_carries_from_the_prior_completed_reconciliation(): void
    {
        $deposit = $this->postBank(1000);
        $service = app(BankReconciliationService::class);
        $first = $service->start($this->acct('1102'), $this->date, 1000);
        $service->toggleCleared($first, $deposit);
        $service->complete($first);

        $second = $service->start($this->acct('1102'), Carbon::now()->addMonth()->toDateString(), 1000);
        $this->assertEqualsWithDelta(1000.0, (float) $second->opening_balance, 0.001);
        // With opening 1000 and nothing new cleared, a 1000 statement already ties out.
        $this->assertEqualsWithDelta(0.0, $second->refresh()->difference(), 0.001);
    }

    public function test_only_one_draft_per_account(): void
    {
        $service = app(BankReconciliationService::class);
        $service->start($this->acct('1102'), $this->date, 0);

        $this->expectException(BankReconciliationException::class);
        $service->start($this->acct('1102'), Carbon::now()->addDay()->toDateString(), 0);
    }

    public function test_cannot_clear_a_line_from_a_different_account(): void
    {
        // A cash-account line is not eligible for a bank-account reconciliation.
        $cashJournal = app(PostingEngine::class)->post(new LedgerEntry(
            entryDate: $this->date,
            lines: [LedgerLine::debit($this->acct('1101'), 100, 'Cash'), LedgerLine::credit($this->acct('3100'), 100, 'Cap')],
            type: 'manual',
        ));
        $cashLine = (int) JournalLine::where('journal_id', $cashJournal->id)->where('account_id', $this->acct('1101'))->value('id');

        $service = app(BankReconciliationService::class);
        $recon = $service->start($this->acct('1102'), $this->date, 0);

        $this->expectException(BankReconciliationException::class);
        $service->toggleCleared($recon, $cashLine);
    }

    public function test_statement_date_is_normalised(): void
    {
        $recon = app(BankReconciliationService::class)->start($this->acct('1102'), '2026-1-5', 0);
        $this->assertSame('2026-01-05', $recon->statement_date->toDateString());
    }

    public function test_ordering_guard_understands_non_padded_dates(): void
    {
        $deposit = $this->postBank(1000);
        $service = app(BankReconciliationService::class);
        $first = $service->start($this->acct('1102'), '2026-09-30', 1000);
        $service->toggleCleared($first, $deposit);
        $service->complete($first);

        // "2026-1-5" is Jan 5 (before Sep 30) — it must be rejected, not accepted because
        // the raw string sorts after "2026-09-30" lexically.
        $this->expectException(BankReconciliationException::class);
        $service->start($this->acct('1102'), '2026-1-5', 0);
    }

    public function test_reopen_is_limited_to_the_latest_completed(): void
    {
        $d1 = $this->postBank(1000);
        $service = app(BankReconciliationService::class);
        $first = $service->start($this->acct('1102'), $this->date, 1000);
        $service->toggleCleared($first, $d1);
        $service->complete($first);

        $second = $service->start($this->acct('1102'), Carbon::now()->addMonth()->toDateString(), 1000);
        $service->complete($second); // opening 1000, ties out immediately

        $this->expectException(BankReconciliationException::class);
        $service->reopen($first); // a newer reconciliation exists
    }
}
