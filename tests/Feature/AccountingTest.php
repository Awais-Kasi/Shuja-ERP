<?php

namespace Tests\Feature;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\LedgerReports;
use App\Ledger\PostingEngine;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Co', 'code' => 'T', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY Test',
            'starts_on' => Carbon::now()->startOfYear(),
            'ends_on' => Carbon::now()->endOfYear(),
            'status' => 'open',
        ]);

        AccountingPeriod::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $fy->id,
            'name' => Carbon::now()->format('M Y'),
            'starts_on' => Carbon::now()->startOfMonth(),
            'ends_on' => Carbon::now()->endOfMonth(),
            'status' => 'open',
        ]);

        $this->acc['cash'] = Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset']);
        $this->acc['capital'] = Account::create(['company_id' => $this->company->id, 'code' => '3100', 'name' => 'Capital', 'type' => 'equity']);
        $this->acc['expense'] = Account::create(['company_id' => $this->company->id, 'code' => '6100', 'name' => 'Salaries', 'type' => 'expense']);
        $this->acc['group'] = Account::create(['company_id' => $this->company->id, 'code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'is_group' => true]);
    }

    private function engine(): PostingEngine
    {
        return app(PostingEngine::class);
    }

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    public function test_a_balanced_entry_posts_successfully(): void
    {
        $journal = $this->engine()->post(new LedgerEntry(
            entryDate: $this->today(),
            type: 'opening',
            memo: 'Capital',
            lines: [
                LedgerLine::debit($this->acc['cash']->id, 100000),
                LedgerLine::credit($this->acc['capital']->id, 100000),
            ],
        ));

        $this->assertSame('posted', $journal->status);
        $this->assertNotNull($journal->number);
        $this->assertNotNull($journal->posted_at);
        $this->assertCount(2, $journal->lines);
        $this->assertEquals('100000.0000', $journal->lines->firstWhere('account_id', $this->acc['cash']->id)->base_debit);
    }

    public function test_an_unbalanced_entry_is_rejected(): void
    {
        $this->expectException(PostingException::class);

        $this->engine()->post(new LedgerEntry(
            entryDate: $this->today(),
            lines: [
                LedgerLine::debit($this->acc['cash']->id, 100000),
                LedgerLine::credit($this->acc['capital']->id, 90000),
            ],
        ));

        $this->assertSame(0, Journal::count());
    }

    public function test_posting_to_a_group_account_is_rejected(): void
    {
        $this->expectExceptionMessage('group/inactive');

        $this->engine()->post(new LedgerEntry(
            entryDate: $this->today(),
            lines: [
                LedgerLine::debit($this->acc['group']->id, 100),
                LedgerLine::credit($this->acc['capital']->id, 100),
            ],
        ));
    }

    public function test_posting_to_a_locked_period_is_rejected(): void
    {
        AccountingPeriod::query()->update(['status' => 'locked']);

        $this->expectExceptionMessage('locked');

        $this->engine()->post(new LedgerEntry(
            entryDate: $this->today(),
            lines: [
                LedgerLine::debit($this->acc['cash']->id, 100),
                LedgerLine::credit($this->acc['capital']->id, 100),
            ],
        ));
    }

    public function test_posting_outside_any_period_is_rejected(): void
    {
        $this->expectExceptionMessage('No accounting period');

        $this->engine()->post(new LedgerEntry(
            entryDate: Carbon::now()->addYears(5)->toDateString(),
            lines: [
                LedgerLine::debit($this->acc['cash']->id, 100),
                LedgerLine::credit($this->acc['capital']->id, 100),
            ],
        ));
    }

    public function test_reversal_creates_a_mirror_journal(): void
    {
        $original = $this->engine()->post(new LedgerEntry(
            entryDate: $this->today(),
            lines: [
                LedgerLine::debit($this->acc['expense']->id, 5000),
                LedgerLine::credit($this->acc['cash']->id, 5000),
            ],
        ));

        $reversal = $this->engine()->reverse($original);

        $this->assertSame($original->id, $reversal->reverses_id);
        $this->assertEquals('5000.0000', $reversal->lines->firstWhere('account_id', $this->acc['expense']->id)->base_credit);
        $this->assertEquals('5000.0000', $reversal->lines->firstWhere('account_id', $this->acc['cash']->id)->base_debit);
    }

    public function test_trial_balance_balances_after_posting(): void
    {
        $this->engine()->post(new LedgerEntry(
            entryDate: $this->today(),
            lines: [
                LedgerLine::debit($this->acc['cash']->id, 250000),
                LedgerLine::credit($this->acc['capital']->id, 250000),
            ],
        ));

        $tb = app(LedgerReports::class)->trialBalance();

        $this->assertEqualsWithDelta($tb['totals']['debit'], $tb['totals']['credit'], 0.001);
        $this->assertEqualsWithDelta(250000, $tb['totals']['debit'], 0.001);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }
}
