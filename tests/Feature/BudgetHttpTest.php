<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BudgetHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'BGH', 'code' => 'BGH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $this->fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $this->fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        Account::create(['company_id' => $this->company->id, 'code' => '6100', 'name' => 'Salaries', 'type' => 'expense', 'control_type' => null]);
        Account::create(['company_id' => $this->company->id, 'code' => '1102', 'name' => 'Bank', 'type' => 'asset', 'control_type' => 'bank']);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Budgeting'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    private function budget(): Budget
    {
        return Budget::create(['company_id' => $this->company->id, 'fiscal_year_id' => $this->fy->id, 'name' => 'Ops', 'status' => 'active']);
    }

    public function test_viewing_requires_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/budgeting/budgets')->assertForbidden();
        $this->actingAs($this->userWith(['budget.view']))->get('/budgeting/budgets')->assertOk();
    }

    public function test_creating_requires_manage(): void
    {
        $payload = ['fiscal_year_id' => $this->fy->id, 'name' => 'Operating'];
        $this->actingAs($this->userWith(['budget.view']))->post('/budgeting/budgets', $payload)->assertForbidden();
        $this->assertSame(0, Budget::count());

        $this->actingAs($this->userWith(['budget.manage']))->post('/budgeting/budgets', $payload)->assertRedirect();
        $this->assertSame(1, Budget::count());
    }

    public function test_a_non_pnl_account_is_rejected_on_a_budget_line(): void
    {
        $budget = $this->budget();
        $payload = ['account_id' => Account::where('code', '1102')->value('id'), 'annual_amount' => 1000]; // asset
        $this->actingAs($this->userWith(['budget.manage']))->post("/budgeting/budgets/{$budget->id}/lines", $payload)->assertSessionHasErrors('account_id');
        $this->assertSame(0, $budget->lines()->count());
    }

    public function test_a_pnl_line_is_accepted(): void
    {
        $budget = $this->budget();
        $payload = ['account_id' => Account::where('code', '6100')->value('id'), 'annual_amount' => 12000];
        $this->actingAs($this->userWith(['budget.manage']))->post("/budgeting/budgets/{$budget->id}/lines", $payload)->assertRedirect();
        $this->assertSame(1, $budget->lines()->count());
    }
}
