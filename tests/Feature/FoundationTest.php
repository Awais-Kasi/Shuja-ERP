<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NumberSequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): TenantManager
    {
        return app(TenantManager::class);
    }

    private function company(string $name, string $code): Company
    {
        return Company::create([
            'name' => $name,
            'code' => $code,
            'base_currency' => 'PKR',
        ]);
    }

    public function test_tenant_scope_isolates_rows_between_companies(): void
    {
        $a = $this->company('Alpha', 'A');
        $b = $this->company('Beta', 'B');

        $this->tenant()->set($a);
        NumberSequence::create(['key' => 'seq', 'prefix' => 'A-']);

        $this->tenant()->set($b);
        NumberSequence::create(['key' => 'seq', 'prefix' => 'B-']);

        // Under company B, only B's row is visible and it is auto-stamped.
        $this->assertSame(1, NumberSequence::count());
        $this->assertSame('B-', NumberSequence::first()->prefix);
        $this->assertSame($b->id, NumberSequence::first()->company_id);

        // Switching context reveals A's row instead.
        $this->tenant()->set($a);
        $this->assertSame(1, NumberSequence::count());
        $this->assertSame('A-', NumberSequence::first()->prefix);

        // Without a tenant, the scope is inert and both rows are visible.
        $this->tenant()->forget();
        $this->assertSame(2, NumberSequence::count());
    }

    public function test_rbac_grants_ability_only_via_company_role(): void
    {
        $company = $this->company('Gamma', 'G');
        $this->tenant()->set($company);

        $post = Permission::create(['name' => 'sales.invoice.post', 'label' => 'Post invoice', 'group' => 'Sales']);
        Permission::create(['name' => 'accounting.journal.post', 'label' => 'Post journal', 'group' => 'Accounting']);

        $role = Role::create(['company_id' => $company->id, 'name' => 'Sales', 'slug' => 'sales']);
        $role->permissions()->attach($post->id);

        $user = User::factory()->create();
        $user->companies()->attach($company->id, ['role_id' => $role->id, 'is_default' => true]);

        $this->assertTrue($user->hasPermission('sales.invoice.post'));
        $this->assertFalse($user->hasPermission('accounting.journal.post'));

        // The Gate::before hook routes ability checks through RBAC.
        $this->assertTrue(Gate::forUser($user)->allows('sales.invoice.post'));
        $this->assertFalse(Gate::forUser($user)->allows('accounting.journal.post'));
    }

    public function test_super_admin_bypasses_permission_checks(): void
    {
        $company = $this->company('Delta', 'D');
        $this->tenant()->set($company);

        $user = User::factory()->create(['is_super_admin' => true]);

        $this->assertTrue($user->hasPermission('anything.at.all'));
    }

    public function test_number_sequence_allocates_and_formats_sequentially(): void
    {
        $company = $this->company('Epsilon', 'E');
        $this->tenant()->set($company);

        NumberSequence::create(['key' => 'sales_invoice', 'prefix' => 'INV-', 'padding' => 5, 'next_number' => 1]);

        $this->assertSame('INV-00001', NumberSequence::next('sales_invoice'));
        $this->assertSame('INV-00002', NumberSequence::next('sales_invoice'));
        $this->assertSame(3, NumberSequence::where('key', 'sales_invoice')->first()->next_number);
    }

    protected function tearDown(): void
    {
        $this->tenant()->forget();
        parent::tearDown();
    }
}
