<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'RPH', 'code' => 'RPH', 'base_currency' => 'PKR']);
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

    public function test_aging_reports_need_report_view(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/reports/aged-receivables')->assertForbidden();
        $this->actingAs($this->userWith(['accounting.report.view']))->get('/reports/aged-receivables')->assertOk();
        $this->actingAs($this->userWith(['accounting.report.view']))->get('/reports/aged-payables')->assertOk();
        $this->actingAs($this->userWith(['accounting.report.view']))->get('/reports/sales-tax')->assertOk();
    }

    public function test_inventory_reports_need_valuation_view(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/reports/inventory-valuation')->assertForbidden();
        $this->actingAs($this->userWith(['inventory.valuation.view']))->get('/reports/inventory-valuation')->assertOk();
        $this->actingAs($this->userWith(['inventory.valuation.view']))->get('/reports/stock-aging')->assertOk();
    }
}
