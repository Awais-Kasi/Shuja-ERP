<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'RHT', 'code' => 'RHT', 'base_currency' => 'PKR']);
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'X'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_sales_returns_require_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/sales/returns')->assertForbidden();
        $this->actingAs($this->userWith(['sales.return.create']))->get('/sales/returns')->assertOk();
    }

    public function test_purchase_returns_require_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/purchase/returns')->assertForbidden();
        $this->actingAs($this->userWith(['purchase.return.create']))->get('/purchase/returns')->assertOk();
    }
}
