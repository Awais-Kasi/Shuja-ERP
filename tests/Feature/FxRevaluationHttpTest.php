<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FxRevaluationHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'FXH', 'code' => 'FXH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        Account::create(['company_id' => $this->company->id, 'code' => '4400', 'name' => 'FX', 'type' => 'income', 'control_type' => null]);
        Currency::updateOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'is_active' => true]);
        Currency::updateOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'is_active' => true]);

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

    public function test_viewing_requires_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/accounting/fx-revaluation')->assertForbidden();
        $this->actingAs($this->userWith(['accounting.fx.view']))->get('/accounting/fx-revaluation')->assertOk();
    }

    public function test_managing_rates_requires_manage(): void
    {
        $payload = ['quote_code' => 'USD', 'rate' => 285.5, 'rate_date' => now()->toDateString()];
        $this->actingAs($this->userWith(['accounting.fx.view']))->post('/accounting/fx-revaluation/rates', $payload)->assertForbidden();
        $this->assertSame(0, ExchangeRate::count());

        $this->actingAs($this->userWith(['accounting.fx.manage']))->post('/accounting/fx-revaluation/rates', $payload)->assertRedirect();
        $this->assertSame(1, ExchangeRate::count());
    }

    public function test_base_currency_is_rejected_as_a_rate(): void
    {
        $payload = ['quote_code' => 'PKR', 'rate' => 1, 'rate_date' => now()->toDateString()];
        $this->actingAs($this->userWith(['accounting.fx.manage']))->post('/accounting/fx-revaluation/rates', $payload)->assertSessionHasErrors('quote_code');
    }

    public function test_running_requires_manage(): void
    {
        $this->actingAs($this->userWith(['accounting.fx.view']))->post('/accounting/fx-revaluation', ['date' => now()->toDateString()])->assertForbidden();
        // A manage user is allowed through to the service (which redirects back even with nothing to revalue).
        $this->actingAs($this->userWith(['accounting.fx.manage']))->post('/accounting/fx-revaluation', ['date' => now()->toDateString()])->assertRedirect();
    }
}
