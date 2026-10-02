<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Payroll\PayrollService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HrHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'HR Co', 'code' => 'HR', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        foreach ([
            ['6100', 'Salaries', 'expense', null], ['6110', 'Emp EOBI', 'expense', null], ['6120', 'Emp PF', 'expense', null],
            ['2130', 'Accrued', 'liability', null], ['2140', 'Wages Payable', 'liability', 'wages_payable'],
            ['2141', 'Tax Payable', 'liability', null], ['2142', 'EOBI Payable', 'liability', null], ['2143', 'PF Payable', 'liability', null],
            ['1101', 'Cash', 'asset', 'cash'],
        ] as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }

        CostCenter::create(['company_id' => $this->company->id, 'code' => 'HO', 'name' => 'HO']);

        app(TenantManager::class)->forget();
    }

    /** @param array<int, string> $abilities */
    private function userWith(array $abilities): User
    {
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        foreach ($abilities as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'group' => 'HR'])->id);
        }
        $user = User::factory()->create(['default_company_id' => $this->company->id, 'email_verified_at' => now()]);
        $user->companies()->attach($this->company->id, ['role_id' => $role->id, 'is_default' => true]);

        return $user;
    }

    private function employeePayload(string $code = 'EMP-1'): array
    {
        return [
            'code' => $code,
            'name' => 'Test Employee',
            'employment_type' => 'salaried',
            'payment_method' => 'bank',
            'basic_salary' => 80000,
            'house_rent' => 20000,
        ];
    }

    public function test_permitted_user_can_create_employee(): void
    {
        $user = $this->userWith(['hr.employee.manage']);
        $this->actingAs($user)->post('/hr/employees', $this->employeePayload())->assertRedirect();

        $this->assertSame(1, Employee::count());
    }

    public function test_user_without_ability_cannot_create_employee(): void
    {
        $user = $this->userWith(['hr.attendance.manage']); // wrong ability
        $this->actingAs($user)->post('/hr/employees', $this->employeePayload())->assertForbidden();

        $this->assertSame(0, Employee::count());
    }

    public function test_run_payroll_creates_and_posts(): void
    {
        $user = $this->userWith(['hr.employee.manage', 'hr.payroll.run']);
        $this->actingAs($user)->post('/hr/employees', $this->employeePayload())->assertRedirect();

        $this->actingAs($user)->post('/hr/payroll', [
            'period_year' => (int) Carbon::now()->year,
            'period_month' => (int) Carbon::now()->month,
            'accrual_date' => Carbon::now()->toDateString(),
        ])->assertRedirect();

        $run = PayrollRun::firstOrFail();
        $this->assertSame('draft', $run->status);

        $this->actingAs($user)->post("/hr/payroll/{$run->id}/post")->assertRedirect();
        $this->assertSame('posted', $run->refresh()->status);
    }

    public function test_disbursing_requires_pay_permission(): void
    {
        // Build & post a run out of band.
        app(TenantManager::class)->set($this->company);
        Employee::create([
            'company_id' => $this->company->id,
            'salary_expense_account_id' => Account::where('code', '6100')->value('id'),
            'code' => 'EMP-1', 'name' => 'E', 'employment_type' => 'salaried', 'payment_method' => 'bank',
            'basic_salary' => 80000, 'is_active' => true,
        ]);
        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun((int) Carbon::now()->year, (int) Carbon::now()->month, Carbon::now()->toDateString()));
        app(TenantManager::class)->forget();

        $cashId = (int) Account::where('code', '1101')->value('id');

        $noPay = $this->userWith(['hr.payroll.run']); // lacks hr.payroll.pay
        $this->actingAs($noPay)->post("/hr/payroll/{$run->id}/pay", [
            'paid_from_account_id' => $cashId,
            'payment_date' => Carbon::now()->toDateString(),
        ])->assertForbidden();

        $payer = $this->userWith(['hr.payroll.pay']);
        $this->actingAs($payer)->post("/hr/payroll/{$run->id}/pay", [
            'paid_from_account_id' => $cashId,
            'payment_date' => Carbon::now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame('paid', $run->refresh()->status);
    }

    private function draftRunWithEmployee(int $month): PayrollRun
    {
        app(TenantManager::class)->set($this->company);
        Employee::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'EMP-1'],
            [
                'salary_expense_account_id' => Account::where('code', '6100')->value('id'),
                'name' => 'E', 'employment_type' => 'salaried', 'payment_method' => 'bank',
                'basic_salary' => 80000, 'is_active' => true,
            ],
        );
        $run = app(PayrollService::class)->buildRun(2026, $month, "2026-{$month}-15");
        app(TenantManager::class)->forget();

        return $run;
    }

    public function test_permitted_user_can_view_a_payslip_document(): void
    {
        $run = $this->draftRunWithEmployee(8);
        $slip = $run->payslips->first();

        $user = $this->userWith(['hr.payroll.run']);
        $this->actingAs($user)->get("/hr/payroll/{$run->id}/payslip/{$slip->id}")->assertOk();
    }

    public function test_viewing_a_payslip_requires_the_payroll_permission(): void
    {
        $run = $this->draftRunWithEmployee(8);
        $slip = $run->payslips->first();

        $user = $this->userWith(['hr.attendance.manage']); // wrong ability
        $this->actingAs($user)->get("/hr/payroll/{$run->id}/payslip/{$slip->id}")->assertForbidden();
    }

    public function test_payslip_from_another_run_is_not_found(): void
    {
        $runA = $this->draftRunWithEmployee(8);
        $runB = $this->draftRunWithEmployee(9);
        $slipA = $runA->payslips->first();

        $user = $this->userWith(['hr.payroll.run']);
        // The slip belongs to run A, not run B → 404 guard.
        $this->actingAs($user)->get("/hr/payroll/{$runB->id}/payslip/{$slipA->id}")->assertNotFound();
        // Correct pairing still works.
        $this->actingAs($user)->get("/hr/payroll/{$runA->id}/payslip/{$slipA->id}")->assertOk();
    }

    public function test_statutory_report_is_gated_and_renders(): void
    {
        $this->draftRunWithEmployee(8);

        $this->actingAs($this->userWith(['hr.attendance.manage']))->get('/hr/payroll/statutory-report')->assertForbidden();
        $this->actingAs($this->userWith(['hr.payroll.run']))->get('/hr/payroll/statutory-report')->assertOk();
    }

    public function test_configuring_payroll_requires_permission(): void
    {
        $noConfigure = $this->userWith(['hr.payroll.run']); // lacks hr.payroll.configure
        $this->actingAs($noConfigure)->get('/hr/payroll/settings')->assertForbidden();

        $configurer = $this->userWith(['hr.payroll.configure']);
        $this->actingAs($configurer)->get('/hr/payroll/settings')->assertOk();
    }

    public function test_saving_payroll_settings_persists_and_validates_slabs(): void
    {
        $configurer = $this->userWith(['hr.payroll.configure']);

        // A slab table that does not start at 0 is rejected.
        $this->actingAs($configurer)->put('/hr/payroll/settings', [
            'eobi_wage_base' => 37000, 'eobi_employee_rate' => 0.01, 'eobi_employer_rate' => 0.05, 'pf_rate' => 0.0833,
            'slabs' => [['lower_bound' => 100000, 'base_tax' => 0, 'rate' => 0.05]],
        ])->assertSessionHasErrors('slabs');

        // A valid payload persists the settings and replaces the slab set.
        $this->actingAs($configurer)->put('/hr/payroll/settings', [
            'eobi_wage_base' => 40000, 'eobi_employee_rate' => 0.01, 'eobi_employer_rate' => 0.06, 'pf_rate' => 0.10,
            'slabs' => [
                ['lower_bound' => 0, 'base_tax' => 0, 'rate' => 0.0],
                ['lower_bound' => 600000, 'base_tax' => 0, 'rate' => 0.05],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payroll_settings', ['company_id' => $this->company->id]);
        $this->assertSame(2, \App\Models\PayrollTaxSlab::where('company_id', $this->company->id)->count());
    }
}
