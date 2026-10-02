<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountingHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'HTTP Co', 'code' => 'H', 'base_currency' => 'PKR']);

        $fy = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY',
            'starts_on' => Carbon::now()->startOfYear(),
            'ends_on' => Carbon::now()->endOfYear(),
            'status' => 'open',
        ]);
        AccountingPeriod::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $fy->id,
            'name' => Carbon::now()->format('M Y'),
            'starts_on' => Carbon::now()->startOfMonth(),
            'ends_on' => Carbon::now()->endOfMonth(),
            'status' => 'open',
        ]);

        $this->acc['cash'] = Account::create(['company_id' => $this->company->id, 'code' => '1101', 'name' => 'Cash', 'type' => 'asset']);
        $this->acc['capital'] = Account::create(['company_id' => $this->company->id, 'code' => '3100', 'name' => 'Capital', 'type' => 'equity']);
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Role', 'slug' => 'role-'.uniqid()]);

        foreach ($abilities as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'Accounting']);
            $role->permissions()->attach($permission->id);
        }

        $user = User::factory()->create([
            'default_company_id' => $this->company->id,
            'email_verified_at' => now(),
        ]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    public function test_a_permitted_user_can_post_a_journal_over_http(): void
    {
        $user = $this->userWith(['accounting.journal.post']);

        $response = $this->actingAs($user)->post('/accounting/journals', [
            'entry_date' => Carbon::now()->toDateString(),
            'memo' => 'Test capital',
            'lines' => [
                ['account_id' => $this->acc['cash']->id, 'debit' => 100000, 'credit' => null],
                ['account_id' => $this->acc['capital']->id, 'debit' => null, 'credit' => 100000],
            ],
        ]);

        $journal = Journal::first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);
        $response->assertRedirect(route('accounting.journals.show', $journal));
    }

    public function test_an_unbalanced_post_is_rejected_with_an_error(): void
    {
        $user = $this->userWith(['accounting.journal.post']);

        $response = $this->actingAs($user)->post('/accounting/journals', [
            'entry_date' => Carbon::now()->toDateString(),
            'lines' => [
                ['account_id' => $this->acc['cash']->id, 'debit' => 100000, 'credit' => null],
                ['account_id' => $this->acc['capital']->id, 'debit' => null, 'credit' => 90000],
            ],
        ]);

        $response->assertSessionHasErrors('posting');
        $this->assertSame(0, Journal::count());
    }

    public function test_a_user_without_the_ability_is_forbidden(): void
    {
        $user = $this->userWith(['accounting.journal.view']); // can view, cannot post

        $this->actingAs($user)->post('/accounting/journals', [
            'entry_date' => Carbon::now()->toDateString(),
            'lines' => [
                ['account_id' => $this->acc['cash']->id, 'debit' => 100000, 'credit' => null],
                ['account_id' => $this->acc['capital']->id, 'debit' => null, 'credit' => 100000],
            ],
        ])->assertForbidden();

        $this->assertSame(0, Journal::count());
    }
}
