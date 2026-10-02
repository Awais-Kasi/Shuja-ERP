<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankReconciliation;
use App\Models\Budget;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\RecurringJournal;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Route-model binding must run with the tenant already bound, so a user in one
 * company can never resolve another company's records by id (cross-tenant IDOR).
 */
class CrossTenantBindingTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('AAA');
        $this->companyB = $this->makeCompany('BBB');
    }

    private function makeCompany(string $code): Company
    {
        $company = Company::create(['name' => $code, 'code' => $code, 'base_currency' => 'PKR']);
        $fy = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);
        Account::create(['company_id' => $company->id, 'code' => '1102', 'name' => 'Bank', 'type' => 'asset', 'control_type' => 'bank']);

        return $company;
    }

    /** A user in company A holding every relevant permission. */
    private function userInA(): User
    {
        $abilities = ['budget.view', 'budget.manage', 'banking.view', 'banking.reconcile', 'accounting.recurring.view', 'accounting.recurring.manage'];
        $role = Role::create(['company_id' => $this->companyA->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'X'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->companyA->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->companyA->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    private function budgetInB(): Budget
    {
        return Budget::create(['company_id' => $this->companyB->id, 'fiscal_year_id' => FiscalYear::where('company_id', $this->companyB->id)->value('id'), 'name' => 'Secret', 'status' => 'active']);
    }

    private function recurringInB(): RecurringJournal
    {
        return RecurringJournal::create(['company_id' => $this->companyB->id, 'name' => 'Secret', 'frequency' => 'monthly', 'interval' => 1, 'start_date' => now()->toDateString(), 'next_run_date' => now()->toDateString(), 'status' => 'active']);
    }

    private function reconciliationInB(): BankReconciliation
    {
        return BankReconciliation::create(['company_id' => $this->companyB->id, 'bank_account_id' => Account::where('company_id', $this->companyB->id)->value('id'), 'statement_date' => now()->toDateString(), 'opening_balance' => 0, 'statement_balance' => 0, 'status' => 'draft']);
    }

    public function test_cannot_view_another_companys_budget(): void
    {
        $budget = $this->budgetInB();
        $this->actingAs($this->userInA())->get("/budgeting/budgets/{$budget->id}")->assertNotFound();
    }

    public function test_cannot_view_another_companys_recurring_journal(): void
    {
        $t = $this->recurringInB();
        $this->actingAs($this->userInA())->get("/accounting/recurring/{$t->id}")->assertNotFound();
    }

    public function test_cannot_delete_another_companys_recurring_journal(): void
    {
        $t = $this->recurringInB();
        $this->actingAs($this->userInA())->delete("/accounting/recurring/{$t->id}")->assertNotFound();
        $this->assertDatabaseHas('recurring_journals', ['id' => $t->id]); // still there
    }

    public function test_cannot_view_another_companys_reconciliation(): void
    {
        $recon = $this->reconciliationInB();
        $this->actingAs($this->userInA())->get("/banking/reconciliations/{$recon->id}")->assertNotFound();
    }

    public function test_can_still_reach_own_companys_records(): void
    {
        // Sanity: the same user CAN open a budget in their own company.
        app(TenantManager::class)->set($this->companyA);
        $own = Budget::create(['company_id' => $this->companyA->id, 'fiscal_year_id' => FiscalYear::where('company_id', $this->companyA->id)->value('id'), 'name' => 'Mine', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $this->actingAs($this->userInA())->get("/budgeting/budgets/{$own->id}")->assertOk();
    }
}
