<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FixedAssetHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'FAH', 'code' => 'FAH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        foreach ([
            ['1510', 'PPE', 'asset', null], ['1520', 'Accum', 'asset', null], ['6600', 'Dep', 'expense', null], ['1102', 'Bank', 'asset', 'bank'],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }
        // A group (non-postable) account, to prove validation rejects it.
        Account::create(['company_id' => $this->company->id, 'code' => '1500', 'name' => 'Fixed Assets (group)', 'type' => 'asset', 'is_group' => true]);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Fixed Assets'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    private function payload(): array
    {
        return [
            'code' => 'FA-1', 'name' => 'Van',
            'asset_account_id' => Account::where('code', '1510')->value('id'),
            'accum_account_id' => Account::where('code', '1520')->value('id'),
            'depreciation_account_id' => Account::where('code', '6600')->value('id'),
            'funding_account_id' => Account::where('code', '1102')->value('id'),
            'cost' => 500000, 'salvage_value' => 50000, 'useful_life_months' => 60,
            'acquisition_date' => Carbon::now()->toDateString(),
        ];
    }

    public function test_viewing_requires_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/assets')->assertForbidden();
        $this->actingAs($this->userWith(['fixedasset.view']))->get('/assets')->assertOk();
    }

    public function test_creating_an_asset_requires_manage(): void
    {
        $this->actingAs($this->userWith(['fixedasset.view']))->post('/assets', $this->payload())->assertForbidden();
        $this->assertSame(0, FixedAsset::count());

        $this->actingAs($this->userWith(['fixedasset.manage']))->post('/assets', $this->payload())->assertRedirect();
        $this->assertSame(1, FixedAsset::count());
    }

    public function test_a_group_account_is_rejected_for_depreciation_accounts(): void
    {
        $payload = $this->payload();
        $payload['accum_account_id'] = Account::where('code', '1500')->value('id'); // group account

        $this->actingAs($this->userWith(['fixedasset.manage']))->post('/assets', $payload)->assertSessionHasErrors('accum_account_id');
        $this->assertSame(0, FixedAsset::count());
    }

    public function test_running_depreciation_requires_permission(): void
    {
        $this->actingAs($this->userWith(['fixedasset.view']))->get('/assets/depreciation')->assertForbidden();
        $this->actingAs($this->userWith(['fixedasset.depreciate']))->get('/assets/depreciation')->assertOk();
    }
}
