<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Ledger\PostingException;
use App\Models\Account;
use App\Models\Employee;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Payroll\HrException;
use App\Payroll\PayrollPaymentService;
use App\Payroll\PayrollService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayrollRunController extends Controller
{
    private const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public function index(): Response
    {
        $runs = PayrollRun::query()
            ->withCount('payslips')
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'period' => self::MONTHS[$r->period_month].' '.$r->period_year,
                'status' => $r->status,
                'employees' => $r->payslips_count,
                'gross_total' => (float) $r->gross_total,
                'net_total' => (float) $r->net_total,
            ]);

        return Inertia::render('hr/payroll/index', [
            'runs' => $runs,
            'summary' => [
                'runs' => $runs->count(),
                'posted' => $runs->whereIn('status', ['posted', 'partially_paid', 'paid'])->count(),
                'net_ytd' => round($runs->whereIn('status', ['posted', 'partially_paid', 'paid'])->sum('net_total'), 2),
            ],
        ]);
    }

    public function statutory(TenantManager $tenant): Response
    {
        $companyId = $tenant->id();

        // Outstanding liability balances still to be deposited (credit-positive).
        $balance = fn (string $code) => round((float) DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.company_id', $companyId)->where('j.status', 'posted')
            ->where('a.code', $code)
            ->sum(DB::raw('l.base_credit - l.base_debit')), 2);

        $rows = DB::table('payslips as p')
            ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
            ->where('r.company_id', $companyId)
            ->whereIn('r.status', ['posted', 'partially_paid', 'paid'])
            ->groupBy('r.id', 'r.number', 'r.period_year', 'r.period_month')
            ->select(
                'r.id', 'r.number', 'r.period_year', 'r.period_month',
                DB::raw('SUM(p.gross_earnings) as gross'),
                DB::raw('SUM(p.income_tax) as income_tax'),
                DB::raw('SUM(p.eobi + p.employer_eobi) as eobi'),
                DB::raw('SUM(p.provident_fund + p.employer_pf) as pf'),
                DB::raw('SUM(p.net_pay) as net'),
            )
            ->orderByDesc('r.period_year')->orderByDesc('r.period_month')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'period' => self::MONTHS[$r->period_month].' '.$r->period_year,
                'gross' => round((float) $r->gross, 2),
                'income_tax' => round((float) $r->income_tax, 2),
                'eobi' => round((float) $r->eobi, 2),
                'pf' => round((float) $r->pf, 2),
                'net' => round((float) $r->net, 2),
            ]);

        return Inertia::render('hr/payroll/statutory', [
            'outstanding' => [
                'income_tax' => $balance('2141'),
                'eobi' => $balance('2142'),
                'pf' => $balance('2143'),
                'wages_payable' => $balance('2140'),
            ],
            'rows' => $rows,
            'totals' => [
                'gross' => round((float) $rows->sum('gross'), 2),
                'income_tax' => round((float) $rows->sum('income_tax'), 2),
                'eobi' => round((float) $rows->sum('eobi'), 2),
                'pf' => round((float) $rows->sum('pf'), 2),
                'net' => round((float) $rows->sum('net'), 2),
            ],
        ]);
    }

    public function create(): Response
    {
        $now = now();

        return Inertia::render('hr/payroll/create', [
            'months' => collect(self::MONTHS)->map(fn ($name, $n) => ['value' => $n, 'label' => $name])->values(),
            'defaults' => [
                'period_year' => (int) $now->year,
                'period_month' => (int) $now->month,
                'accrual_date' => $now->copy()->endOfMonth()->toDateString(),
            ],
            'activeEmployees' => Employee::where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request, PayrollService $service): RedirectResponse
    {
        $validated = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'accrual_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $run = $service->buildRun(
                (int) $validated['period_year'],
                (int) $validated['period_month'],
                $validated['accrual_date'],
                null,
                $validated['memo'] ?? null,
            );
        } catch (HrException $e) {
            return back()->withErrors(['period' => $e->getMessage()])->withInput();
        }

        return redirect()->route('hr.payroll.show', $run)->with('success', "Draft payroll {$run->number} created.");
    }

    public function post(PayrollRun $run, PayrollService $service): RedirectResponse
    {
        try {
            $service->post($run);
        } catch (HrException|PostingException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()->route('hr.payroll.show', $run)->with('success', "Payroll {$run->number} posted.");
    }

    public function reverse(PayrollRun $run, PayrollService $service): RedirectResponse
    {
        try {
            $service->reverse($run);
        } catch (HrException|PostingException $e) {
            return back()->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()->route('hr.payroll.show', $run)->with('success', "Payroll {$run->number} reversed.");
    }

    public function reversePayment(PayrollPayment $payment, PayrollPaymentService $service): RedirectResponse
    {
        try {
            $service->reverse($payment);
        } catch (HrException|PostingException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('hr.payroll.show', $payment->payroll_run_id)->with('success', "Payment {$payment->number} reversed.");
    }

    public function pay(Request $request, PayrollRun $run, PayrollPaymentService $service, TenantManager $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'paid_from_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $tenant->id())
                    ->whereIn('control_type', ['cash', 'bank'])
                    ->where(fn ($q) => $q->where('is_group', false)),
            ],
            'payment_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payment = $service->pay($run, (int) $validated['paid_from_account_id'], $validated['payment_date'], $validated['memo'] ?? null);
        } catch (HrException|PostingException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('hr.payroll.show', $run)->with('success', "Payroll disbursed — {$payment->number}.");
    }

    public function show(PayrollRun $run): Response
    {
        $run->load([
            'payslips.employee:id,code,name,department,payment_method',
            'journal:id,number',
            'reversalJournal:id,number',
            'payments' => fn ($q) => $q->orderBy('id'),
            'payments.paidFromAccount:id,code,name',
            'payments.journal:id,number',
            'payments.reversalJournal:id,number',
        ]);

        return Inertia::render('hr/payroll/show', [
            'run' => [
                'id' => $run->id,
                'number' => $run->number,
                'period' => self::MONTHS[$run->period_month].' '.$run->period_year,
                'accrual_date' => $run->accrual_date->toDateString(),
                'status' => $run->status,
                'memo' => $run->memo,
                'journal' => $run->journal?->number,
                'journal_id' => $run->journal_id,
                'reversal_journal' => $run->reversalJournal?->number,
                'reversal_journal_id' => $run->reversal_journal_id,
                'payments' => $run->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'date' => $p->payment_date->toDateString(),
                    'account' => $p->paidFromAccount ? $p->paidFromAccount->code.' — '.$p->paidFromAccount->name : null,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'journal' => $p->journal?->number,
                    'journal_id' => $p->journal_id,
                    'reversal_journal' => $p->reversalJournal?->number,
                    'reversal_journal_id' => $p->reversal_journal_id,
                ]),
                'gross_total' => (float) $run->gross_total,
                'deduction_total' => (float) $run->deduction_total,
                'lop_total' => (float) $run->lop_total,
                'employer_contrib_total' => (float) $run->employer_contrib_total,
                'net_total' => (float) $run->net_total,
                'payslips' => $run->payslips->map(fn ($p) => [
                    'id' => $p->id,
                    'employee' => $p->employee->code.' — '.$p->employee->name,
                    'department' => $p->employee->department,
                    'method' => $p->employee->payment_method,
                    'gross' => (float) $p->gross_earnings,
                    'lop_days' => (float) $p->lop_days,
                    'loss_of_pay' => (float) $p->loss_of_pay,
                    'income_tax' => (float) $p->income_tax,
                    'eobi' => (float) $p->eobi,
                    'provident_fund' => (float) $p->provident_fund,
                    'other_deduction' => (float) $p->other_deduction,
                    'total_deductions' => (float) $p->total_deductions,
                    'employer_eobi' => (float) $p->employer_eobi,
                    'employer_pf' => (float) $p->employer_pf,
                    'net_pay' => (float) $p->net_pay,
                    'paid_amount' => (float) $p->paid_amount,
                    'status' => $p->status,
                ]),
            ],
            'payAccounts' => Account::whereIn('control_type', ['cash', 'bank'])->where('is_group', false)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'today' => now()->toDateString(),
        ]);
    }

    public function payslip(PayrollRun $run, Payslip $payslip, TenantManager $tenant): Response
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);

        $payslip->load('employee.costCenter:id,code,name');
        $company = $tenant->get();
        $e = $payslip->employee;

        return Inertia::render('hr/payroll/payslip', [
            'company' => [
                'name' => $company?->name,
                'legal_name' => $company?->legal_name,
                'address' => $company?->address,
                'tax_no' => $company?->tax_registration_no,
                'currency' => $company?->base_currency ?? 'PKR',
            ],
            'run' => [
                'id' => $run->id,
                'number' => $run->number,
                'period' => self::MONTHS[$run->period_month].' '.$run->period_year,
                'accrual_date' => $run->accrual_date->toDateString(),
                'status' => $run->status,
            ],
            'employee' => [
                'code' => $e->code,
                'name' => $e->name,
                'designation' => $e->designation,
                'department' => $e->department,
                'cost_center' => $e->costCenter ? $e->costCenter->code.' — '.$e->costCenter->name : null,
                'cnic' => $e->cnic,
                'eobi_no' => $e->eobi_no,
                'employment_type' => $e->employment_type,
                'payment_method' => $e->payment_method,
                'bank_name' => $e->bank_name,
                'bank_account_no' => $e->bank_account_no,
                'date_joined' => $e->date_joined?->toDateString(),
            ],
            'payslip' => [
                'id' => $payslip->id,
                'basic' => (float) $payslip->basic,
                'house_rent' => (float) $payslip->house_rent,
                'medical' => (float) $payslip->medical,
                'conveyance' => (float) $payslip->conveyance,
                'other_allowance' => (float) $payslip->other_allowance,
                'gross_earnings' => (float) $payslip->gross_earnings,
                'lop_days' => (float) $payslip->lop_days,
                'loss_of_pay' => (float) $payslip->loss_of_pay,
                'income_tax' => (float) $payslip->income_tax,
                'eobi' => (float) $payslip->eobi,
                'provident_fund' => (float) $payslip->provident_fund,
                'other_deduction' => (float) $payslip->other_deduction,
                'total_deductions' => (float) $payslip->total_deductions,
                'employer_eobi' => (float) $payslip->employer_eobi,
                'employer_pf' => (float) $payslip->employer_pf,
                'net_pay' => (float) $payslip->net_pay,
                'paid_amount' => (float) $payslip->paid_amount,
                'status' => $payslip->status,
            ],
        ]);
    }
}
