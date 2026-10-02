<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PeriodHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fy;

    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'PGH', 'code' => 'PGH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $this->fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        $this->period = AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $this->fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Accounting'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_viewing_requires_lock_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/accounting/periods')->assertForbidden();
        $this->actingAs($this->userWith(['accounting.period.lock']))->get('/accounting/periods')->assertOk();
    }

    public function test_locking_requires_lock_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->post("/accounting/periods/{$this->period->id}/lock")->assertForbidden();
        $this->assertSame('open', $this->period->refresh()->status);

        $this->actingAs($this->userWith(['accounting.period.lock']))->post("/accounting/periods/{$this->period->id}/lock")->assertRedirect();
        $this->assertSame('locked', $this->period->refresh()->status);
    }

    public function test_closing_a_year_requires_close_permission(): void
    {
        // The lock permission alone must not allow a year-end close.
        $this->actingAs($this->userWith(['accounting.period.lock']))->post("/accounting/fiscal-years/{$this->fy->id}/close")->assertForbidden();
        // A closer is allowed through to the service (which redirects back, even with nothing to close).
        $this->actingAs($this->userWith(['accounting.period.close']))->post("/accounting/fiscal-years/{$this->fy->id}/close")->assertRedirect();
    }
}
