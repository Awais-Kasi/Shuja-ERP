<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
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

class RecurringJournalHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'RJH', 'code' => 'RJH', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        Account::create(['company_id' => $this->company->id, 'code' => '6200', 'name' => 'Rent', 'type' => 'expense', 'control_type' => null]);
        Account::create(['company_id' => $this->company->id, 'code' => '1102', 'name' => 'Bank', 'type' => 'asset', 'control_type' => 'bank']);

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

    private function payload(float $debit = 1000, float $credit = 1000): array
    {
        return [
            'name' => 'Monthly Rent', 'frequency' => 'monthly', 'interval' => 1, 'start_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => Account::where('code', '6200')->value('id'), 'debit' => $debit],
                ['account_id' => Account::where('code', '1102')->value('id'), 'credit' => $credit],
            ],
        ];
    }

    public function test_viewing_requires_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/accounting/recurring')->assertForbidden();
        $this->actingAs($this->userWith(['accounting.recurring.view']))->get('/accounting/recurring')->assertOk();
    }

    public function test_creating_requires_manage(): void
    {
        $this->actingAs($this->userWith(['accounting.recurring.view']))->post('/accounting/recurring', $this->payload())->assertForbidden();
        $this->assertSame(0, RecurringJournal::count());

        $this->actingAs($this->userWith(['accounting.recurring.manage']))->post('/accounting/recurring', $this->payload())->assertRedirect();
        $this->assertSame(1, RecurringJournal::count());
    }

    public function test_an_unbalanced_template_is_rejected(): void
    {
        $this->actingAs($this->userWith(['accounting.recurring.manage']))->post('/accounting/recurring', $this->payload(1000, 900))->assertSessionHasErrors('posting');
        $this->assertSame(0, RecurringJournal::count());
    }
}
