<?php

namespace Tests\Feature;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\LedgerReports;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = Carbon::now()->startOfYear()->toDateString();
        $this->to = Carbon::now()->toDateString();

        $this->company = Company::create(['name' => 'Fin Co', 'code' => 'FIN', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'Y', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);

        foreach ([
            ['1102', 'Bank', 'asset', 'bank'], ['1110', 'AR', 'asset', 'ar'], ['1510', 'PPE', 'asset', null],
            ['2110', 'AP', 'liability', 'ap'], ['2510', 'Long-term Loan', 'liability', null],
            ['3100', 'Capital', 'equity', null], ['4100', 'Sales', 'income', null],
            ['5100', 'COGS', 'expense', null], ['6200', 'Rent', 'expense', null],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }

        $this->seedLedger();
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function entry(array $lines): void
    {
        app(PostingEngine::class)->post(new LedgerEntry(entryDate: $this->to, lines: $lines, type: 'manual'));
    }

    private function seedLedger(): void
    {
        $this->entry([LedgerLine::debit($this->acct('1102'), 100000), LedgerLine::credit($this->acct('3100'), 100000)]); // capital
        $this->entry([LedgerLine::debit($this->acct('1110'), 50000), LedgerLine::credit($this->acct('4100'), 50000)]);   // sale on credit
        $this->entry([LedgerLine::debit($this->acct('5100'), 30000), LedgerLine::credit($this->acct('1102'), 30000)]);   // cogs paid
        $this->entry([LedgerLine::debit($this->acct('6200'), 5000), LedgerLine::credit($this->acct('1102'), 5000)]);     // rent paid
        $this->entry([LedgerLine::debit($this->acct('1510'), 20000), LedgerLine::credit($this->acct('1102'), 20000)]);   // buy asset
        $this->entry([LedgerLine::debit($this->acct('1102'), 10000), LedgerLine::credit($this->acct('2510'), 10000)]);   // borrow
    }

    public function test_income_statement(): void
    {
        $r = app(LedgerReports::class)->incomeStatement($this->from, $this->to);

        $this->assertEqualsWithDelta(50000, $r['revenue']['total'], 0.01);
        $this->assertEqualsWithDelta(30000, $r['cost_of_sales']['total'], 0.01);
        $this->assertEqualsWithDelta(20000, $r['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(5000, $r['operating_expenses']['total'], 0.01);
        $this->assertEqualsWithDelta(15000, $r['operating_profit'], 0.01);
        $this->assertEqualsWithDelta(15000, $r['net_profit'], 0.01);
    }

    public function test_balance_sheet_balances(): void
    {
        $r = app(LedgerReports::class)->balanceSheet($this->to);

        // Assets: bank 55,000 + AR 50,000 + PPE 20,000 = 125,000
        $this->assertEqualsWithDelta(125000, $r['assets']['total'], 0.01);
        $this->assertEqualsWithDelta(10000, $r['liabilities']['total'], 0.01);      // loan
        $this->assertEqualsWithDelta(115000, $r['equity']['total'], 0.01);          // capital 100k + earnings 15k
        $this->assertTrue($r['balanced']);
    }

    public function test_cash_flow_ties_to_the_bank_movement(): void
    {
        $r = app(LedgerReports::class)->cashFlow($this->from, $this->to);

        $this->assertEqualsWithDelta(0, $r['opening_cash'], 0.01);
        $this->assertEqualsWithDelta(-35000, $r['operating']['total'], 0.01);   // rev 50k - AR 50k - cogs 30k - rent 5k
        $this->assertEqualsWithDelta(-20000, $r['investing']['total'], 0.01);   // PPE purchase
        $this->assertEqualsWithDelta(110000, $r['financing']['total'], 0.01);   // capital 100k + loan 10k
        $this->assertEqualsWithDelta(55000, $r['net_change'], 0.01);
        $this->assertEqualsWithDelta(55000, $r['closing_cash'], 0.01);          // = bank balance
    }
}
