<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\PayrollTaxSlab;
use App\Payroll\PayrollConfig;
use App\Payroll\PayrollService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class HrSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'SHUJA')->first();
        if (! $company) {
            return;
        }

        app(TenantManager::class)->withCompany($company, function () use ($company) {
            $salaryAccount = Account::where('code', '6100')->value('id');
            $ho = CostCenter::where('code', 'HO')->value('id');
            $plant = CostCenter::where('code', 'PLANT')->value('id');

            $roster = [
                ['EMP-001', 'Muhammad Bilal', 'Plant Manager', 'Production', $plant, 'salaried', 'bank', 120000, 48000, 12000, 10000],
                ['EMP-002', 'Fatima Noor', 'Accountant', 'Finance', $ho, 'salaried', 'bank', 90000, 36000, 9000, 8000],
                ['EMP-003', 'Asadullah Khan', 'Machine Operator', 'Production', $plant, 'wage', 'bank', 45000, 18000, 4500, 5000],
                ['EMP-004', 'Sana Riaz', 'Sales Executive', 'Sales', $ho, 'salaried', 'bank', 60000, 24000, 6000, 6000],
                ['EMP-005', 'Imran Aslam', 'Store Keeper', 'Stores', $plant, 'wage', 'cash', 40000, 16000, 4000, 4000],
            ];

            foreach ($roster as [$code, $name, $designation, $department, $costCenter, $type, $method, $basic, $hr, $med, $conv]) {
                Employee::firstOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    [
                        'cost_center_id' => $costCenter,
                        'salary_expense_account_id' => $salaryAccount,
                        'name' => $name,
                        'designation' => $designation,
                        'department' => $department,
                        'employment_type' => $type,
                        'payment_method' => $method,
                        'basic_salary' => $basic,
                        'house_rent' => $hr,
                        'medical' => $med,
                        'conveyance' => $conv,
                        'other_allowance' => 0,
                        'date_joined' => now()->subYears(2)->toDateString(),
                        'is_active' => true,
                    ],
                );
            }

            $this->seedPayrollConfig($company);
            $this->seedAttendance($company);
            $this->seedPayroll();
        });
    }

    private function seedPayrollConfig(Company $company): void
    {
        PayrollSetting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'eobi_wage_base' => PayrollConfig::DEFAULT_EOBI_WAGE_BASE,
                'eobi_employee_rate' => PayrollConfig::DEFAULT_EOBI_EMPLOYEE_RATE,
                'eobi_employer_rate' => PayrollConfig::DEFAULT_EOBI_EMPLOYER_RATE,
                'pf_rate' => PayrollConfig::DEFAULT_PF_RATE,
            ],
        );

        if (PayrollTaxSlab::where('company_id', $company->id)->exists()) {
            return;
        }
        foreach (PayrollConfig::DEFAULT_TAX_SLABS as [$lower, $base, $rate]) {
            PayrollTaxSlab::create([
                'company_id' => $company->id,
                'lower_bound' => $lower,
                'base_tax' => $base,
                'rate' => $rate,
            ]);
        }
    }

    private function seedAttendance(Company $company): void
    {
        if (AttendanceRecord::exists()) {
            return;
        }

        $statuses = ['present', 'present', 'present', 'leave', 'absent'];
        $employees = Employee::orderBy('code')->get();
        $start = Carbon::create(2026, 8, 3); // first Monday-ish of the accrual month

        foreach ($employees as $index => $employee) {
            for ($d = 0; $d < 5; $d++) {
                AttendanceRecord::create([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'attendance_date' => $start->copy()->addDays($d)->toDateString(),
                    'status' => $statuses[($index + $d) % count($statuses)],
                ]);
            }
        }
    }

    private function seedPayroll(): void
    {
        if (PayrollRun::exists()) {
            return;
        }

        $service = app(PayrollService::class);
        $run = $service->buildRun(2026, 8, '2026-08-31', memo: 'August 2026 salaries');
        $service->post($run);
    }
}
