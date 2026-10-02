<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BankReconciliationHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'BRH', 'code' => 'BRH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        Account::create(['company_id' => $this->company->id, 'code' => '1102', 'name' => 'Bank', 'type' => 'asset', 'control_type' => 'bank']);
        // A group account, to prove it is rejected as a reconciliation target.
        Account::create(['company_id' => $this->company->id, 'code' => '1100', 'name' => 'Current Assets', 'type' => 'asset', 'control_type' => 'bank', 'is_group' => true]);
        // A non-bank leaf account, also ineligible.
        Account::create(['company_id' => $this->company->id, 'code' => '4100', 'name' => 'Sales', 'type' => 'income', 'control_type' => null]);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Banking'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_viewing_requires_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/banking/reconciliations')->assertForbidden();
        $this->actingAs($this->userWith(['banking.view']))->get('/banking/reconciliations')->assertOk();
    }

    public function test_starting_requires_reconcile_permission(): void
    {
        $payload = ['bank_account_id' => Account::where('code', '1102')->value('id'), 'statement_date' => now()->toDateString(), 'statement_balance' => 1000];

        $this->actingAs($this->userWith(['banking.view']))->post('/banking/reconciliations', $payload)->assertForbidden();
        $this->assertSame(0, BankReconciliation::count());

        $this->actingAs($this->userWith(['banking.reconcile']))->post('/banking/reconciliations', $payload)->assertRedirect();
        $this->assertSame(1, BankReconciliation::count());
    }

    public function test_a_group_account_is_rejected(): void
    {
        $payload = ['bank_account_id' => Account::where('code', '1100')->value('id'), 'statement_date' => now()->toDateString(), 'statement_balance' => 0];
        $this->actingAs($this->userWith(['banking.reconcile']))->post('/banking/reconciliations', $payload)->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, BankReconciliation::count());
    }

    public function test_a_non_bank_account_is_rejected(): void
    {
        $payload = ['bank_account_id' => Account::where('code', '4100')->value('id'), 'statement_date' => now()->toDateString(), 'statement_balance' => 0];
        $this->actingAs($this->userWith(['banking.reconcile']))->post('/banking/reconciliations', $payload)->assertSessionHasErrors('bank_account_id');
        $this->assertSame(0, BankReconciliation::count());
    }
}
