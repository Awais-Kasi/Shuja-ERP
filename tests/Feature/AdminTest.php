<?php

namespace Tests\Feature;

use App\Companies\CompanyProvisioner;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\NumberSequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Adm Co', 'code' => 'ADM', 'base_currency' => 'PKR']);
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        app(TenantManager::class)->set($this->company);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Platform'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);
        app(TenantManager::class)->forget();

        return $user;
    }

    public function test_provisioner_stands_up_a_full_company(): void
    {
        Permission::firstOrCreate(['name' => 'dashboard.view'], ['label' => 'x', 'group' => 'Platform']);
        Permission::firstOrCreate(['name' => 'accounting.journal.post'], ['label' => 'x', 'group' => 'Accounting']);
        \App\Models\Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'is_active' => true]);
        $creator = User::factory()->create(['default_company_id' => null]);

        $company = app(CompanyProvisioner::class)->provision([
            'name' => 'Acme Ltd', 'code' => 'ACME', 'base_currency' => 'PKR', 'fiscal_start_month' => 7,
        ], $creator);

        $this->assertGreaterThan(30, Account::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(1, FiscalYear::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(12, \App\Models\AccountingPeriod::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertGreaterThan(5, NumberSequence::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $owner = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('slug', 'owner')->first();
        $this->assertNotNull($owner);
        $this->assertSame(Permission::count(), $owner->permissions()->count()); // owner gets every permission
        $this->assertTrue($creator->fresh()->belongsToCompany($company->id));
        $this->assertSame($company->id, $creator->fresh()->default_company_id); // first company becomes default
    }

    public function test_auditable_records_master_data_changes(): void
    {
        app(TenantManager::class)->set($this->company);
        Account::create(['company_id' => $this->company->id, 'code' => '1110', 'name' => 'AR', 'type' => 'asset', 'control_type' => 'ar']);
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C1', 'name' => 'Old Name']);

        $customer->update(['name' => 'New Name']);

        $log = AuditLog::where('auditable_type', $customer->getMorphClass())->where('auditable_id', $customer->id)->where('event', 'updated')->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('name', $log->new_values);
        $this->assertSame('New Name', $log->new_values['name']);
    }

    public function test_user_management_is_permission_gated(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/admin/users')->assertForbidden();
        $this->actingAs($this->userWith(['user.manage']))->get('/admin/users')->assertOk();
    }

    public function test_creating_a_user_attaches_them_to_the_company(): void
    {
        $manager = $this->userWith(['user.manage']);
        $role = Role::where('company_id', $this->company->id)->first();

        $this->actingAs($manager)->post('/admin/users', [
            'name' => 'New Staff', 'email' => 'staff@shuja.test', 'password' => 'Password!234', 'role_id' => $role->id,
        ])->assertRedirect();

        $user = User::where('email', 'staff@shuja.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->belongsToCompany($this->company->id));
        $this->assertFalse($user->is_super_admin); // never set from the UI
    }

    public function test_roles_are_permission_gated_and_system_roles_are_protected(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/admin/roles')->assertForbidden();

        $manager = $this->userWith(['role.manage']);
        app(TenantManager::class)->set($this->company);
        $system = Role::create(['company_id' => $this->company->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($manager)->delete("/admin/roles/{$system->id}")->assertSessionHasErrors('role');
        $this->assertDatabaseHas('roles', ['id' => $system->id]);
    }

    public function test_audit_log_view_is_permission_gated(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/admin/audit')->assertForbidden();
        $this->actingAs($this->userWith(['audit.view']))->get('/admin/audit')->assertOk();
    }
}
