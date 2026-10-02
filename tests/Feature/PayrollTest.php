<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Payroll\HrException;
use App\Payroll\PayrollCalculator;
use App\Payroll\PayrollConfig;
use App\Payroll\PayrollPaymentService;
use App\Payroll\PayrollService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CostCenter $ho;

    private int $year;

    private int $month;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = (int) Carbon::now()->year;
        $this->month = (int) Carbon::now()->month;
        $this->date = Carbon::now()->toDateString();

        $this->company = Company::create(['name' => 'Pay Co', 'code' => 'P', 'base_currency' => 'PKR']);
        app(TenantManager::class)->set($this->company);

        $fy = FiscalYear::create(['company_id' => $this->company->id, 'name' => 'FY', 'starts_on' => Carbon::now()->startOfYear(), 'ends_on' => Carbon::now()->endOfYear(), 'status' => 'open']);
        AccountingPeriod::create(['company_id' => $this->company->id, 'fiscal_year_id' => $fy->id, 'name' => 'P', 'starts_on' => Carbon::now()->startOfMonth(), 'ends_on' => Carbon::now()->endOfMonth(), 'status' => 'open']);

        $accounts = [
            ['6100', 'Salaries & Wages', 'expense', null],
            ['6110', 'Employer EOBI', 'expense', null],
            ['6120', 'Employer PF', 'expense', null],
            ['2130', 'Accrued Expenses', 'liability', null],
            ['2140', 'Wages Payable', 'liability', 'wages_payable'],
            ['2141', 'Income Tax Payable', 'liability', null],
            ['2142', 'EOBI Payable', 'liability', null],
            ['2143', 'PF Payable', 'liability', null],
            ['1101', 'Cash', 'asset', 'cash'],
            ['1102', 'Bank', 'asset', 'bank'],
        ];
        foreach ($accounts as [$code, $name, $type, $control]) {
            Account::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'control_type' => $control]);
        }

        $this->ho = CostCenter::create(['company_id' => $this->company->id, 'code' => 'HO', 'name' => 'Head Office']);
    }

    private function employee(string $code, float $basic, float $houseRent = 0): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'cost_center_id' => $this->ho->id,
            'salary_expense_account_id' => Account::where('code', '6100')->value('id'),
            'code' => $code,
            'name' => "Emp {$code}",
            'employment_type' => 'salaried',
            'payment_method' => 'bank',
            'basic_salary' => $basic,
            'house_rent' => $houseRent,
            'is_active' => true,
        ]);
    }

    private function gl(string $code): float
    {
        return (float) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->sum(DB::raw('l.base_debit - l.base_credit'));
    }

    public function test_income_tax_uses_pk_salaried_slabs(): void
    {
        $calc = new PayrollCalculator;

        // Annual 1,200,000 → only the 5% band applies → 30,000 / 12.
        $this->assertEqualsWithDelta(2500.0, $calc->monthlyIncomeTax(100000), 0.0001);
        // Below the exemption threshold → no tax.
        $this->assertEqualsWithDelta(0.0, $calc->monthlyIncomeTax(40000), 0.0001);
        // Annual 3,600,000 spans four bands → 550,000 / 12.
        $this->assertEqualsWithDelta(45833.3333, $calc->monthlyIncomeTax(300000), 0.0001);
    }

    public function test_accrual_posts_a_balanced_statutory_journal(): void
    {
        $this->employee('E1', 100000); // gross 100,000

        $service = app(PayrollService::class);
        $run = $service->buildRun($this->year, $this->month, $this->date);
        $run = $service->post($run);

        $this->assertSame('posted', $run->status);

        // Expense side.
        $this->assertEqualsWithDelta(100000.0, $this->gl('6100'), 0.001);   // gross
        $this->assertEqualsWithDelta(1850.0, $this->gl('6110'), 0.001);     // employer EOBI 5% of 37,000
        $this->assertEqualsWithDelta(8330.0, $this->gl('6120'), 0.001);     // employer PF 8.33% of basic

        // Liability side (credits are negative in debit-minus-credit).
        $this->assertEqualsWithDelta(-88800.0, $this->gl('2140'), 0.001);   // net pay
        $this->assertEqualsWithDelta(-2500.0, $this->gl('2141'), 0.001);    // income tax
        $this->assertEqualsWithDelta(-2220.0, $this->gl('2142'), 0.001);    // 370 + 1850
        $this->assertEqualsWithDelta(-16660.0, $this->gl('2143'), 0.001);   // 8330 + 8330

        // The journal balances.
        $journal = $run->journal;
        $this->assertEqualsWithDelta((float) $journal->lines->sum('base_debit'), (float) $journal->lines->sum('base_credit'), 0.001);

        // Run rollups.
        $this->assertEqualsWithDelta(100000.0, (float) $run->gross_total, 0.001);
        $this->assertEqualsWithDelta(11200.0, (float) $run->deduction_total, 0.001);
        $this->assertEqualsWithDelta(10180.0, (float) $run->employer_contrib_total, 0.001); // 1850 + 8330
        $this->assertEqualsWithDelta(88800.0, (float) $run->net_total, 0.001);
    }

    public function test_net_pay_lands_in_a_per_employee_subledger(): void
    {
        $e1 = $this->employee('E1', 100000);
        $e2 = $this->employee('E2', 50000);

        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));

        $morph = $e1->getMorphClass();
        $e1Payable = (float) DB::table('journal_lines')->where('party_type', $morph)->where('party_id', $e1->id)->sum('base_credit');
        $e2Payable = (float) DB::table('journal_lines')->where('party_type', $morph)->where('party_id', $e2->id)->sum('base_credit');

        $slip1 = $run->payslips->firstWhere('employee_id', $e1->id);
        $slip2 = $run->payslips->firstWhere('employee_id', $e2->id);

        $this->assertEqualsWithDelta((float) $slip1->net_pay, $e1Payable, 0.001);
        $this->assertEqualsWithDelta((float) $slip2->net_pay, $e2Payable, 0.001);
        $this->assertGreaterThan($e2Payable, $e1Payable);
    }

    public function test_payment_relieves_the_payable_and_marks_paid(): void
    {
        $this->employee('E1', 100000);

        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));

        $this->assertEqualsWithDelta(-88800.0, $this->gl('2140'), 0.001);

        $cashId = (int) Account::where('code', '1101')->value('id');
        $payment = app(PayrollPaymentService::class)->pay($run, $cashId, $this->date);

        $this->assertEqualsWithDelta(88800.0, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->gl('2140'), 0.001);        // payable cleared
        $this->assertEqualsWithDelta(-88800.0, $this->gl('1101'), 0.001);   // cash credited

        $run->refresh()->load('payslips');
        $this->assertSame('paid', $run->status);
        $this->assertSame('paid', $run->payslips->first()->status);
        $this->assertEqualsWithDelta(88800.0, (float) $run->payslips->first()->paid_amount, 0.001);
    }

    public function test_duplicate_period_is_rejected(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $service->buildRun($this->year, $this->month, $this->date);

        $this->expectException(HrException::class);
        $service->buildRun($this->year, $this->month, $this->date);
    }

    public function test_paying_a_draft_run_is_blocked(): void
    {
        $this->employee('E1', 100000);
        $run = app(PayrollService::class)->buildRun($this->year, $this->month, $this->date);

        $this->expectException(HrException::class);
        app(PayrollPaymentService::class)->pay($run, (int) Account::where('code', '1101')->value('id'), $this->date);
    }

    public function test_statutory_contributions_use_the_correct_rates(): void
    {
        $c = (new PayrollCalculator)->forEmployee($this->employee('E1', 100000));

        $this->assertEqualsWithDelta(370.0, $c['eobi'], 0.0001);          // 1% of 37,000
        $this->assertEqualsWithDelta(1850.0, $c['employer_eobi'], 0.0001); // 5% of 37,000 (statutory)
        $this->assertEqualsWithDelta(8330.0, $c['provident_fund'], 0.0001); // 8.33% of basic
        $this->assertEqualsWithDelta(8330.0, $c['employer_pf'], 0.0001);   // employer-matched
    }

    public function test_config_falls_back_to_built_in_defaults_without_rows(): void
    {
        $config = PayrollConfig::forCompany($this->company->id);

        $this->assertEqualsWithDelta(37000.0, $config->eobiWageBase, 1e-9);
        $this->assertEqualsWithDelta(0.05, $config->eobiEmployerRate, 1e-9);
        $this->assertEqualsWithDelta(0.0833, $config->pfRate, 1e-9);
        $this->assertCount(6, $config->taxSlabs);
    }

    public function test_payroll_uses_company_configured_rates(): void
    {
        PayrollSetting::create([
            'company_id' => $this->company->id,
            'eobi_wage_base' => 37000,
            'eobi_employee_rate' => 0.01,
            'eobi_employer_rate' => 0.06, // overridden from the 0.05 default
            'pf_rate' => 0.0833,
        ]);

        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));

        // Employer EOBI now 6% of 37,000 = 2,220 (default would be 1,850).
        $this->assertEqualsWithDelta(2220.0, $this->gl('6110'), 0.001);
        $this->assertEqualsWithDelta((float) $run->journal->lines->sum('base_debit'), (float) $run->journal->lines->sum('base_credit'), 0.001);
    }

    public function test_custom_tax_slabs_change_withholding(): void
    {
        $config = new PayrollConfig(37000, 0.01, 0.05, 0.0833, [[0, 0, 0.0], [500000, 0, 0.10]]);
        $calc = new PayrollCalculator($config);

        // Annual 1,200,000 (100,000/mo): tax = (1,200,000 − 500,000) × 10% = 70,000 → /12.
        $this->assertEqualsWithDelta(5833.3333, $calc->monthlyIncomeTax(100000), 0.001);
    }

    public function test_negative_net_pay_is_rejected_by_name(): void
    {
        // A zero-salary active employee is hit by the flat EOBI levy → net would be negative.
        $this->employee('E-ZERO', 0);

        $this->expectException(HrException::class);
        $this->expectExceptionMessageMatches('/E-ZERO/');
        app(PayrollService::class)->buildRun($this->year, $this->month, $this->date);
    }

    public function test_payroll_cannot_be_disbursed_from_a_non_cash_account(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));

        // 6100 Salaries & Wages is postable but is an expense account, not cash/bank.
        $expenseId = (int) Account::where('code', '6100')->value('id');

        $this->expectException(HrException::class);
        app(PayrollPaymentService::class)->pay($run, $expenseId, $this->date);
    }

    public function test_posted_run_can_be_reversed_and_nets_the_gl_to_zero(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));

        $run = $service->reverse($run);

        $this->assertSame('reversed', $run->status);
        $this->assertNotNull($run->reversal_journal_id);
        $this->assertSame('reversed', $run->payslips->first()->status);

        // Accrual + its mirror net every account to zero.
        foreach (['6100', '6110', '6120', '2140', '2141', '2142', '2143'] as $code) {
            $this->assertEqualsWithDelta(0.0, $this->gl($code), 0.001, "Account {$code} should net to zero after reversal");
        }
    }

    public function test_a_paid_run_cannot_be_reversed_until_the_payment_is(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));
        app(PayrollPaymentService::class)->pay($run, (int) Account::where('code', '1101')->value('id'), $this->date);

        $this->expectException(HrException::class);
        $service->reverse($run->refresh());
    }

    public function test_payment_can_be_reversed_restoring_the_payable(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));

        $cashId = (int) Account::where('code', '1101')->value('id');
        $payment = app(PayrollPaymentService::class)->pay($run, $cashId, $this->date);

        $this->assertEqualsWithDelta(0.0, $this->gl('2140'), 0.001);       // payable cleared by payment
        $this->assertEqualsWithDelta(-88800.0, $this->gl('1101'), 0.001);  // cash out

        $payment = app(PayrollPaymentService::class)->reverse($payment);

        $this->assertSame('reversed', $payment->status);
        $this->assertNotNull($payment->reversal_journal_id);
        $this->assertEqualsWithDelta(0.0, $this->gl('1101'), 0.001);        // cash restored
        $this->assertEqualsWithDelta(-88800.0, $this->gl('2140'), 0.001);   // payable reappears

        $run->refresh()->load('payslips');
        $this->assertSame('posted', $run->status);
        $this->assertSame('posted', $run->payslips->first()->status);
        $this->assertEqualsWithDelta(0.0, (float) $run->payslips->first()->paid_amount, 0.001);

        // With the payable restored, the accrual can now be reversed too.
        $run = $service->reverse($run);
        $this->assertSame('reversed', $run->status);
    }

    public function test_a_reversed_run_cannot_be_paid(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->reverse($service->post($service->buildRun($this->year, $this->month, $this->date)));

        $this->assertSame('reversed', $run->status);

        // The critical guard: a reversed accrual must not disburse cash.
        $this->expectException(HrException::class);
        app(PayrollPaymentService::class)->pay($run, (int) Account::where('code', '1101')->value('id'), $this->date);
    }

    public function test_an_accrual_cannot_be_reversed_twice(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->reverse($service->post($service->buildRun($this->year, $this->month, $this->date)));

        $this->expectException(HrException::class);
        $service->reverse($run);
    }

    public function test_loss_of_pay_from_attendance_reduces_net_and_salary_expense(): void
    {
        $e1 = $this->employee('E1', 100000); // gross 100,000

        // absent×2 (weight 1) + half_day (0.5) + leave (paid) + present (paid) = 2.5 LOP days.
        $marks = [3 => 'absent', 4 => 'absent', 5 => 'half_day', 6 => 'leave', 7 => 'present'];
        foreach ($marks as $day => $status) {
            AttendanceRecord::create([
                'company_id' => $this->company->id,
                'employee_id' => $e1->id,
                'attendance_date' => Carbon::create($this->year, $this->month, $day)->toDateString(),
                'status' => $status,
            ]);
        }

        $daysInMonth = Carbon::create($this->year, $this->month, 1)->daysInMonth;
        $expectedLop = round(100000 * 2.5 / $daysInMonth, 4);

        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));
        $slip = $run->payslips->first();

        $this->assertEqualsWithDelta(2.5, (float) $slip->lop_days, 0.001);
        $this->assertEqualsWithDelta($expectedLop, (float) $slip->loss_of_pay, 0.001);
        $this->assertEqualsWithDelta(round(100000 - 11200 - $expectedLop, 4), (float) $slip->net_pay, 0.001);

        // Salary expense debit is the payable gross (gross − LOP); the journal still balances.
        $this->assertEqualsWithDelta(round(100000 - $expectedLop, 4), $this->gl('6100'), 0.001);
        $this->assertEqualsWithDelta($expectedLop, (float) $run->lop_total, 0.001);
        $journal = $run->journal;
        $this->assertEqualsWithDelta((float) $journal->lines->sum('base_debit'), (float) $journal->lines->sum('base_credit'), 0.001);
    }

    public function test_a_payment_cannot_be_reversed_twice(): void
    {
        $this->employee('E1', 100000);
        $service = app(PayrollService::class);
        $run = $service->post($service->buildRun($this->year, $this->month, $this->date));
        $payment = app(PayrollPaymentService::class)->pay($run, (int) Account::where('code', '1101')->value('id'), $this->date);

        $payment = app(PayrollPaymentService::class)->reverse($payment);
        $this->assertSame('reversed', $payment->status);

        $this->expectException(HrException::class);
        app(PayrollPaymentService::class)->reverse($payment);
    }
}
