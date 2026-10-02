<?php

namespace Tests\Feature;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Periods\PeriodCloseException;
use App\Periods\PeriodCloseService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Close Co', 'code' => 'CLS', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $this->fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create(2026, $m, 1);
            AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $this->fy->id, 'name' => "M{$m}", 'starts_on' => $start, 'ends_on' => $start->copy()->endOfMonth(), 'status' => 'open']);
        }

        foreach ([
            ['1102', 'Bank', 'asset', 'bank'], ['4100', 'Sales', 'income', null], ['6100', 'Expenses', 'expense', null],
            ['3200', 'Retained Earnings', 'equity', 'retained_earnings'],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    private function seedProfit(): void
    {
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: '2026-03-15', type: 'manual',
            lines: [LedgerLine::debit($this->acct('1102'), 100000), LedgerLine::credit($this->acct('4100'), 100000)]));
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: '2026-04-15', type: 'manual',
            lines: [LedgerLine::debit($this->acct('6100'), 40000), LedgerLine::credit($this->acct('1102'), 40000)]));
    }

    public function test_year_close_sweeps_pnl_into_retained_earnings(): void
    {
        $this->seedProfit(); // profit 60,000

        $this->fy = app(PeriodCloseService::class)->closeYear($this->fy);

        $this->assertEqualsWithDelta(0.0, $this->gl('4100'), 0.01);   // income flattened
        $this->assertEqualsWithDelta(0.0, $this->gl('6100'), 0.01);   // expense flattened
        $this->assertEqualsWithDelta(-60000.0, $this->gl('3200'), 0.01); // profit → retained (credit)
        $this->assertSame('closed', $this->fy->status);
        $this->assertNotNull($this->fy->closing_journal_id);
        $this->assertSame(12, AccountingPeriod::where('fiscal_year_id', $this->fy->id)->where('status', 'locked')->count());
    }

    public function test_a_net_loss_debits_retained_earnings(): void
    {
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: '2026-03-15', type: 'manual',
            lines: [LedgerLine::debit($this->acct('1102'), 20000), LedgerLine::credit($this->acct('4100'), 20000)]));
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: '2026-04-15', type: 'manual',
            lines: [LedgerLine::debit($this->acct('6100'), 50000), LedgerLine::credit($this->acct('1102'), 50000)]));

        app(PeriodCloseService::class)->closeYear($this->fy);

        $this->assertEqualsWithDelta(30000.0, $this->gl('3200'), 0.01); // net loss 30k → retained debited
    }

    public function test_closing_with_no_activity_is_rejected(): void
    {
        $this->expectException(PeriodCloseException::class);
        app(PeriodCloseService::class)->closeYear($this->fy);
    }

    public function test_closing_twice_is_rejected(): void
    {
        $this->seedProfit();
        $service = app(PeriodCloseService::class);
        $service->closeYear($this->fy);

        $this->expectException(PeriodCloseException::class);
        $service->closeYear($this->fy->refresh());
    }

    public function test_locking_a_period_blocks_posting(): void
    {
        $march = AccountingPeriod::where('name', 'M3')->first();
        app(PeriodCloseService::class)->lock($march);

        $this->expectException(PostingException::class);
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: '2026-03-20', type: 'manual',
            lines: [LedgerLine::debit($this->acct('6100'), 100), LedgerLine::credit($this->acct('1102'), 100)]));
    }

    public function test_reopening_a_year_reverses_the_close(): void
    {
        $this->seedProfit();
        $service = app(PeriodCloseService::class);
        $service->closeYear($this->fy);

        $service->reopenYear($this->fy->refresh());

        // P&L balances restored, retained back to zero, periods unlocked.
        $this->assertEqualsWithDelta(-100000.0, $this->gl('4100'), 0.01);
        $this->assertEqualsWithDelta(40000.0, $this->gl('6100'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->gl('3200'), 0.01);
        $this->assertSame('open', $this->fy->refresh()->status);
        $this->assertSame(0, AccountingPeriod::where('fiscal_year_id', $this->fy->id)->where('status', 'locked')->count());
    }

    public function test_a_locked_period_can_be_reopened(): void
    {
        $march = AccountingPeriod::where('name', 'M3')->first();
        $service = app(PeriodCloseService::class);
        $service->lock($march);
        $service->reopen($march->refresh());

        $this->assertSame('open', $march->refresh()->status);
    }
}
