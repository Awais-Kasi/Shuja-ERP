<?php

namespace App\Payroll;

use App\Ledger\LedgerEntry;
use App\Ledger\LedgerLine;
use App\Ledger\PostingEngine;
use App\Models\Account;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the payroll accrual. Building a run snapshots each active employee's salary
 * structure and statutory figures into an immutable payslip; posting a run books
 * one balanced journal:
 *
 *   Dr Salaries & Wages          (Σ gross, per salary account + cost centre)
 *   Dr Employer EOBI Contribution
 *   Dr Employer PF Contribution
 *       Cr Wages & Salaries Payable  (net pay, per employee subledger)
 *       Cr Income Tax Payable
 *       Cr EOBI Payable              (employee + employer share)
 *       Cr Provident Fund Payable    (employee + employer share)
 *       Cr Accrued Expenses          (other deductions)
 *
 * The entry balances by construction: net = gross − deductions, so what leaves the
 * expense side always lands in a payable.
 */
class PayrollService
{
    private const EPSILON = 1e-9;

    /**
     * Attendance statuses that dock pay, and their weight in lost days. 'leave' is
     * treated as PAID (approved leave); 'present'/'holiday' are paid. Loss of pay is
     * prorated over the calendar days in the month.
     *
     * @var array<string, float>
     */
    private const LOP_WEIGHTS = ['absent' => 1.0, 'half_day' => 0.5];

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly TenantManager $tenant,
    ) {}

    /**
     * Create a draft run for a period with one draft payslip per active employee.
     *
     * @param  array<int, int>|null  $employeeIds  restrict to these employees; null = all active
     */
    public function buildRun(int $year, int $month, ?string $accrualDate = null, ?array $employeeIds = null, ?string $memo = null): PayrollRun
    {
        if ($month < 1 || $month > 12) {
            throw new HrException('Payroll month must be between 1 and 12.');
        }

        $exists = PayrollRun::where('period_year', $year)->where('period_month', $month)->exists();
        if ($exists) {
            throw new HrException('A payroll run already exists for this period.');
        }

        $query = Employee::where('is_active', true);
        if ($employeeIds !== null) {
            $query->whereIn('id', $employeeIds);
        }
        $employees = $query->orderBy('code')->get();

        if ($employees->isEmpty()) {
            throw new HrException('No active employees to include in this payroll run.');
        }

        $accrual = $accrualDate ?: Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        try {
            return $this->createRun($year, $month, $accrual, $employees, $memo);
        } catch (UniqueConstraintViolationException $e) {
            // The pre-check above is not atomic; the (company_id, period_year, period_month)
            // unique index is the real guard. Translate a lost race into the same friendly error.
            throw new HrException('A payroll run already exists for this period.');
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function createRun(int $year, int $month, string $accrual, $employees, ?string $memo): PayrollRun
    {
        return DB::transaction(function () use ($year, $month, $accrual, $employees, $memo) {
            $run = PayrollRun::create([
                'company_id' => $this->tenant->id(),
                'number' => $this->allocateNumber('payroll_run', 'PR-'),
                'period_year' => $year,
                'period_month' => $month,
                'accrual_date' => $accrual,
                'status' => 'draft',
                'memo' => $memo,
                'created_by' => optional(auth()->user())->id,
            ]);

            // Snapshot the company's statutory config for this run's calculations.
            $calculator = new PayrollCalculator(PayrollConfig::forCompany($this->tenant->id()));

            $gross = $ded = $lop = $employer = $net = 0.0;

            foreach ($employees as $employee) {
                $slip = $this->buildPayslip($run, $employee, $calculator);
                $gross = round($gross + (float) $slip->gross_earnings, 4);
                $ded = round($ded + (float) $slip->total_deductions, 4);
                $lop = round($lop + (float) $slip->loss_of_pay, 4);
                $employer = round($employer + (float) $slip->employer_eobi + (float) $slip->employer_pf, 4);
                $net = round($net + (float) $slip->net_pay, 4);
            }

            $run->update([
                'gross_total' => $gross,
                'deduction_total' => $ded,
                'lop_total' => $lop,
                'employer_contrib_total' => $employer,
                'net_total' => $net,
            ]);

            return $run->load('payslips.employee');
        });
    }

    private function buildPayslip(PayrollRun $run, Employee $employee, PayrollCalculator $calculator): Payslip
    {
        $c = $calculator->forEmployee($employee);

        $lop = $this->lossOfPay($employee, $run->period_year, $run->period_month, $c['gross']);

        $totalDeductions = round($c['income_tax'] + $c['eobi'] + $c['provident_fund'], 4);
        $netPay = round($c['gross'] - $totalDeductions - $lop['amount'], 4);

        // A negative net (statutory deductions + loss of pay exceeding gross) cannot be
        // represented as a payable credit and would silently unbalance the accrual. Fail
        // fast, naming the employee, so attendance/salary is corrected before posting.
        if ($netPay < -self::EPSILON) {
            throw new HrException("Employee {$employee->code} — {$employee->name}: net pay after deductions and loss-of-pay is negative. Review the salary structure and attendance before running payroll.");
        }

        return Payslip::create([
            'company_id' => $this->tenant->id(),
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'basic' => $employee->basic_salary,
            'house_rent' => $employee->house_rent,
            'medical' => $employee->medical,
            'conveyance' => $employee->conveyance,
            'other_allowance' => $employee->other_allowance,
            'gross_earnings' => $c['gross'],
            'lop_days' => $lop['days'],
            'loss_of_pay' => $lop['amount'],
            'income_tax' => $c['income_tax'],
            'eobi' => $c['eobi'],
            'provident_fund' => $c['provident_fund'],
            'other_deduction' => 0,
            'total_deductions' => $totalDeductions,
            'employer_eobi' => $c['employer_eobi'],
            'employer_pf' => $c['employer_pf'],
            'net_pay' => $netPay,
            'paid_amount' => 0,
            'status' => 'posted',
        ]);
    }

    /**
     * Loss of pay for an employee in the given month, from their attendance records,
     * prorated over the calendar days in the month.
     *
     * @return array{days: float, amount: float}
     */
    private function lossOfPay(Employee $employee, int $year, int $month, float $gross): array
    {
        $start = Carbon::create($year, $month, 1);
        $daysInMonth = $start->daysInMonth;

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->get(['status']);

        $lopDays = 0.0;
        foreach ($records as $record) {
            $lopDays += self::LOP_WEIGHTS[$record->status] ?? 0.0;
        }
        $lopDays = round($lopDays, 2);

        $amount = $daysInMonth > 0 ? round($gross * $lopDays / $daysInMonth, 4) : 0.0;

        return ['days' => $lopDays, 'amount' => $amount];
    }

    public function post(PayrollRun $run): PayrollRun
    {
        if ($run->isPosted()) {
            throw new HrException('This payroll run is already posted.');
        }

        $run->load('payslips.employee');
        if ($run->payslips->isEmpty()) {
            throw new HrException('This run has no payslips to post.');
        }

        $wagesPayable = $this->control('wages_payable', 'Wages & Salaries Payable (2140)');
        $incomeTaxAcc = $this->byCode('2141', 'Income Tax Payable');
        $eobiPayable = $this->byCode('2142', 'EOBI Payable');
        $pfPayable = $this->byCode('2143', 'Provident Fund Payable');
        $accrued = $this->byCode('2130', 'Accrued Expenses');
        $employerEobiExp = $this->byCode('6110', 'Employer EOBI Contribution');
        $employerPfExp = $this->byCode('6120', 'Employer PF Contribution');
        $defaultSalaryExp = $this->byCode('6100', 'Salaries & Wages');

        return DB::transaction(function () use (
            $run, $wagesPayable, $incomeTaxAcc, $eobiPayable, $pfPayable,
            $accrued, $employerEobiExp, $employerPfExp, $defaultSalaryExp
        ) {
            // Serialise concurrent posts: a double-submit sees 'posted' on the locked
            // row and aborts rather than posting the accrual twice.
            $locked = PayrollRun::whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'draft') {
                throw new HrException('This payroll run is already posted.');
            }

            $date = $run->accrual_date->toDateString();
            $number = $run->number ?: $this->allocateNumber('payroll_run', 'PR-');

            $salaryByKey = [];   // "acct:cc" => [account, cc, amount]
            $payableLines = [];  // per-employee net-pay credits (subledger)
            $grossTotal = $incomeTax = $eobi = $pf = $otherDed = $empEobi = $empPf = $netTotal = $dedTotal = $lopTotal = 0.0;

            foreach ($run->payslips as $slip) {
                $employee = $slip->employee;
                $salaryAccount = $employee->salary_expense_account_id ?: $defaultSalaryExp->id;
                $costCenter = $employee->cost_center_id;
                $key = $salaryAccount.':'.($costCenter ?? 0);

                $gross = round((float) $slip->gross_earnings, 4);
                $lossOfPay = round((float) $slip->loss_of_pay, 4);
                // The expense debit is the PAYABLE gross: unworked unpaid (LOP) days are
                // not an employer expense. This keeps the entry balanced against the net
                // credit, which is also reduced by the same LOP.
                $payableGross = round($gross - $lossOfPay, 4);
                $salaryByKey[$key]['account'] = (int) $salaryAccount;
                $salaryByKey[$key]['cc'] = $costCenter;
                $salaryByKey[$key]['amount'] = round(($salaryByKey[$key]['amount'] ?? 0) + $payableGross, 4);

                $grossTotal = round($grossTotal + $gross, 4);
                $lopTotal = round($lopTotal + $lossOfPay, 4);
                $incomeTax = round($incomeTax + (float) $slip->income_tax, 4);
                $eobi = round($eobi + (float) $slip->eobi, 4);
                $pf = round($pf + (float) $slip->provident_fund, 4);
                $otherDed = round($otherDed + (float) $slip->other_deduction, 4);
                $empEobi = round($empEobi + (float) $slip->employer_eobi, 4);
                $empPf = round($empPf + (float) $slip->employer_pf, 4);
                $dedTotal = round($dedTotal + (float) $slip->total_deductions, 4);

                $net = round((float) $slip->net_pay, 4);
                $netTotal = round($netTotal + $net, 4);

                if ($net > self::EPSILON) {
                    $payableLines[] = new LedgerLine(
                        accountId: $wagesPayable->id,
                        credit: $net,
                        description: "Net pay — {$employee->name}",
                        partyType: $employee->getMorphClass(),
                        partyId: $employee->id,
                    );
                }
            }

            $lines = [];
            foreach ($salaryByKey as $group) {
                if ($group['amount'] > self::EPSILON) {
                    $lines[] = new LedgerLine(
                        accountId: $group['account'],
                        debit: $group['amount'],
                        costCenterId: $group['cc'],
                        description: 'Salaries & wages',
                    );
                }
            }
            if ($empEobi > self::EPSILON) {
                $lines[] = LedgerLine::debit($employerEobiExp->id, $empEobi, 'Employer EOBI contribution');
            }
            if ($empPf > self::EPSILON) {
                $lines[] = LedgerLine::debit($employerPfExp->id, $empPf, 'Employer PF contribution');
            }

            foreach ($payableLines as $line) {
                $lines[] = $line;
            }

            if ($incomeTax > self::EPSILON) {
                $lines[] = LedgerLine::credit($incomeTaxAcc->id, $incomeTax, 'Income tax withheld');
            }
            $eobiCredit = round($eobi + $empEobi, 4);
            if ($eobiCredit > self::EPSILON) {
                $lines[] = LedgerLine::credit($eobiPayable->id, $eobiCredit, 'EOBI payable');
            }
            $pfCredit = round($pf + $empPf, 4);
            if ($pfCredit > self::EPSILON) {
                $lines[] = LedgerLine::credit($pfPayable->id, $pfCredit, 'Provident fund payable');
            }
            if ($otherDed > self::EPSILON) {
                $lines[] = LedgerLine::credit($accrued->id, $otherDed, 'Other payroll deductions');
            }

            $journal = $this->posting->post(new LedgerEntry(
                entryDate: $date,
                lines: $lines,
                type: 'payroll_accrual',
                reference: $number,
                memo: $run->memo ?: "Payroll accrual {$number}",
                source: $run,
            ));

            $run->update([
                'number' => $number,
                'status' => 'posted',
                'gross_total' => $grossTotal,
                'deduction_total' => $dedTotal,
                'lop_total' => $lopTotal,
                'employer_contrib_total' => round($empEobi + $empPf, 4),
                'net_total' => $netTotal,
                'journal_id' => $journal->id,
                'posted_at' => now(),
            ]);

            return $run->load('journal', 'payslips.employee');
        });
    }

    /**
     * Reverse a posted (but undisbursed) accrual by posting a mirror journal. The
     * original stays for the audit trail; the two net to zero. Any disbursement must
     * be reversed first — otherwise the payable subledger would go inconsistent.
     */
    public function reverse(PayrollRun $run): PayrollRun
    {
        if ($run->status === 'draft') {
            throw new HrException('A draft run has no accrual to reverse.');
        }
        if ($run->isReversed()) {
            throw new HrException('This payroll run is already reversed.');
        }
        if (in_array($run->status, ['partially_paid', 'paid'], true)) {
            throw new HrException('Reverse the payroll payment(s) before reversing the accrual.');
        }
        if (! $run->journal_id) {
            throw new HrException('This run has no accrual journal to reverse.');
        }

        return DB::transaction(function () use ($run) {
            // Serialise concurrent reversals: only a still-'posted' run may be reversed,
            // so a double-submit cannot post two mirror journals.
            $locked = PayrollRun::whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'posted') {
                throw new HrException('This payroll run can no longer be reversed.');
            }

            $reversal = $this->posting->reverse($run->journal, now()->toDateString());

            $run->payslips()->update(['status' => 'reversed']);
            $run->update([
                'status' => 'reversed',
                'reversal_journal_id' => $reversal->id,
                'reversed_at' => now(),
            ]);

            return $run->load('journal', 'reversalJournal', 'payslips.employee');
        });
    }

    private function control(string $controlType, string $label): Account
    {
        $account = Account::where('control_type', $controlType)->first();
        if (! $account) {
            throw new HrException("No control account configured for {$label}.");
        }

        return $account;
    }

    private function byCode(string $code, string $label): Account
    {
        $account = Account::where('code', $code)->first();
        if (! $account) {
            throw new HrException("Account {$code} ({$label}) is not configured.");
        }

        return $account;
    }

    private function allocateNumber(string $key, string $prefix): string
    {
        NumberSequence::firstOrCreate(
            ['company_id' => $this->tenant->id(), 'key' => $key],
            ['prefix' => $prefix, 'padding' => 5, 'next_number' => 1],
        );

        return NumberSequence::next($key);
    }
}
