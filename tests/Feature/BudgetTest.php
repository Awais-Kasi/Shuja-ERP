<?php

namespace Tests\Feature;

use App\Budgeting\BudgetException;
use App\Budgeting\BudgetService;
use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Bud Co', 'code' => 'BUD', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $this->fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create(2026, $m, 1);
            AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $this->fy->id, 'name' => "M{$m}", 'starts_on' => $start, 'ends_on' => $start->copy()->endOfMonth(), 'status' => 'open']);
        }

        foreach ([
            ['1102', 'Bank', 'asset', 'bank'], ['4100', 'Sales', 'income', null], ['6100', 'Salaries', 'expense', null],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }

        CostCenter::create(['company_id' => $this->company->id, 'code' => 'HO', 'name' => 'Head Office', 'dimension' => 'business', 'is_active' => true]);
        CostCenter::create(['company_id' => $this->company->id, 'code' => 'PL', 'name' => 'Plant', 'dimension' => 'business', 'is_active' => true]);
    }

    private function acct(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function cc(string $code): int
    {
        return (int) CostCenter::where('code', $code)->value('id');
    }

    private function postExpense(float $amount, string $date, ?int $ccId = null): void
    {
        app(PostingEngine::class)->post(new LedgerEntry(
            entryDate: $date,
            lines: [new LedgerLine($this->acct('6100'), debit: $amount, costCenterId: $ccId), LedgerLine::credit($this->acct('1102'), $amount)],
            type: 'manual',
        ));
    }

    private function postIncome(float $amount, string $date): void
    {
        app(PostingEngine::class)->post(new LedgerEntry(
            entryDate: $date,
            lines: [LedgerLine::debit($this->acct('1102'), $amount), LedgerLine::credit($this->acct('4100'), $amount)],
            type: 'manual',
        ));
    }

    private function budget(): Budget
    {
        return app(BudgetService::class)->createBudget($this->fy->id, 'Ops');
    }

    public function test_only_income_or_expense_accounts_can_be_budgeted(): void
    {
        $budget = $this->budget();
        $this->expectException(BudgetException::class);
        app(BudgetService::class)->setLine($budget, $this->acct('1102'), null, 1000); // asset
    }

    public function test_set_line_is_an_upsert(): void
    {
        $budget = $this->budget();
        $service = app(BudgetService::class);
        $service->setLine($budget, $this->acct('6100'), null, 1200);
        $service->setLine($budget, $this->acct('6100'), null, 2400);

        $this->assertSame(1, $budget->lines()->count());
        $this->assertEqualsWithDelta(2400.0, (float) $budget->lines()->first()->annual_amount, 0.001);
    }

    public function test_expense_variance_is_favourable_when_under_budget(): void
    {
        $budget = $this->budget();
        $service = app(BudgetService::class);
        $service->setLine($budget, $this->acct('6100'), null, 1200); // 100 / month

        $this->postExpense(100, '2026-01-31');
        $this->postExpense(100, '2026-02-28');
        $this->postExpense(100, '2026-03-31');

        $atThree = $service->variance($budget, 3);
        $row = $atThree['rows'][0];
        $this->assertEqualsWithDelta(300.0, $row['budget_ytd'], 0.001);
        $this->assertEqualsWithDelta(300.0, $row['actual_ytd'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['variance'], 0.001);

        $atSix = $service->variance($budget, 6);
        $row6 = $atSix['rows'][0];
        $this->assertEqualsWithDelta(600.0, $row6['budget_ytd'], 0.001); // pro-rated
        $this->assertEqualsWithDelta(300.0, $row6['actual_ytd'], 0.001); // no new spend
        $this->assertEqualsWithDelta(-300.0, $row6['variance'], 0.001);
        $this->assertTrue($row6['favorable']); // under budget = good for expense
    }

    public function test_income_variance_is_favourable_when_over_target(): void
    {
        $budget = $this->budget();
        $service = app(BudgetService::class);
        $service->setLine($budget, $this->acct('4100'), null, 12000); // 1000 / month

        $this->postIncome(5000, '2026-02-15');

        $row = $service->variance($budget, 3)['rows'][0];
        $this->assertEqualsWithDelta(3000.0, $row['budget_ytd'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $row['actual_ytd'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $row['variance'], 0.001);
        $this->assertTrue($row['favorable']);
    }

    public function test_actuals_respect_the_as_of_cutoff(): void
    {
        $budget = $this->budget();
        $service = app(BudgetService::class);
        $service->setLine($budget, $this->acct('6100'), null, 1200);

        $this->postExpense(100, '2026-01-15');
        $this->postExpense(500, '2026-08-15'); // later than the cutoff below

        $row = $service->variance($budget, 3)['rows'][0];
        $this->assertEqualsWithDelta(100.0, $row['actual_ytd'], 0.001); // August spend excluded
    }

    public function test_a_cost_centre_line_only_counts_its_own_actuals(): void
    {
        $budget = $this->budget();
        $service = app(BudgetService::class);
        $service->setLine($budget, $this->acct('6100'), $this->cc('HO'), 1200);

        $this->postExpense(100, '2026-01-15', $this->cc('HO'));
        $this->postExpense(999, '2026-01-16', $this->cc('PL')); // different cost centre

        $row = $service->variance($budget, 3)['rows'][0];
        $this->assertEqualsWithDelta(100.0, $row['actual_ytd'], 0.001);
    }
}
