import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { amountInWords, money } from '@/lib/format';

type Company = { name: string | null; legal_name: string | null; address: string | null; tax_no: string | null; currency: string };
type Run = { id: number; number: string | null; period: string; accrual_date: string; status: string };
type Employee = {
    code: string; name: string; designation: string | null; department: string | null; cost_center: string | null;
    cnic: string | null; eobi_no: string | null; employment_type: string; payment_method: string;
    bank_name: string | null; bank_account_no: string | null; date_joined: string | null;
};
type Payslip = {
    id: number; basic: number; house_rent: number; medical: number; conveyance: number; other_allowance: number;
    gross_earnings: number; lop_days: number; loss_of_pay: number; income_tax: number; eobi: number; provident_fund: number; other_deduction: number;
    total_deductions: number; employer_eobi: number; employer_pf: number; net_pay: number; paid_amount: number; status: string;
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

function Line({ label, value, currency, strong }: { label: string; value: number; currency: string; strong?: boolean }) {
    return (
        <div className={`flex items-center justify-between px-4 py-2 ${strong ? 'font-semibold' : ''}`}>
            <span className={strong ? '' : 'text-slate-600'}>{label}</span>
            <span className="font-mono tabular-nums">{money(value)} <span className="text-xs text-slate-400">{currency}</span></span>
        </div>
    );
}

export default function PayslipDocument({ company, run, employee, payslip }: { company: Company; run: Run; employee: Employee; payslip: Payslip }) {
    const cur = company.currency;
    const initials = (employee.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();

    return (
        <div className="min-h-screen bg-slate-100 py-8 text-slate-900 print:bg-white print:py-0">
            <Head title={`Payslip ${employee.code} — ${run.period}`} />
            <style>{`@media print { @page { size: A4; margin: 14mm; } .no-print { display: none !important; } body { background: #fff; } }`}</style>

            {/* Toolbar (screen only) */}
            <div className="no-print mx-auto mb-4 flex max-w-[820px] items-center justify-between px-4">
                <Link href={`/hr/payroll/${run.id}`} className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
                    <ArrowLeft className="size-4" /> Back to run
                </Link>
                <button
                    onClick={() => window.print()}
                    className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
                    style={gradient('#4f46e5', '#7c3aed')}
                >
                    <Printer className="size-4" /> Print / Save PDF
                </button>
            </div>

            {/* A4 document */}
            <div className="mx-auto max-w-[820px] bg-white p-10 shadow-lg print:max-w-none print:p-0 print:shadow-none">
                {/* Header */}
                <div className="flex items-start justify-between border-b-2 border-slate-800 pb-5">
                    <div>
                        <div className="text-2xl font-bold tracking-tight">{company.name ?? 'Company'}</div>
                        {company.legal_name && <div className="text-sm text-slate-500">{company.legal_name}</div>}
                        {company.address && <div className="mt-1 max-w-xs text-xs text-slate-500">{company.address}</div>}
                        {company.tax_no && <div className="text-xs text-slate-500">NTN/STRN: {company.tax_no}</div>}
                    </div>
                    <div className="text-right">
                        <div className="text-lg font-bold uppercase tracking-widest text-indigo-700">Payslip</div>
                        <div className="mt-1 text-sm text-slate-600">{run.period}</div>
                        <div className="text-xs text-slate-400">Run {run.number ?? '—'} · Accrued {run.accrual_date}</div>
                    </div>
                </div>

                {/* Employee block */}
                <div className="mt-6 flex items-center gap-4 rounded-xl bg-slate-50 p-4 print:bg-slate-50">
                    <div className="flex size-12 shrink-0 items-center justify-center rounded-full text-lg font-bold text-white" style={gradient('#6366f1', '#7c3aed')}>
                        {initials}
                    </div>
                    <div className="grid flex-1 grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                        <Field label="Employee" value={`${employee.code} — ${employee.name}`} />
                        <Field label="Designation" value={employee.designation ?? '—'} />
                        <Field label="Department" value={employee.department ?? '—'} />
                        <Field label="Cost Center" value={employee.cost_center ?? '—'} />
                        <Field label="CNIC" value={employee.cnic ?? '—'} />
                        <Field label="EOBI No." value={employee.eobi_no ?? '—'} />
                        <Field label="Type" value={employee.employment_type} className="capitalize" />
                        <Field label="Paid via" value={employee.payment_method === 'bank' ? `Bank${employee.bank_account_no ? ` · ${employee.bank_account_no}` : ''}` : 'Cash'} className="capitalize" />
                        <Field label="Joined" value={employee.date_joined ?? '—'} />
                    </div>
                </div>

                {/* Earnings + Deductions */}
                <div className="mt-6 grid gap-6 sm:grid-cols-2">
                    <div className="overflow-hidden rounded-xl border">
                        <div className="bg-emerald-600 px-4 py-2 text-sm font-semibold uppercase tracking-wide text-white">Earnings</div>
                        <div className="divide-y text-sm">
                            <Line label="Basic Salary" value={payslip.basic} currency={cur} />
                            <Line label="House Rent" value={payslip.house_rent} currency={cur} />
                            <Line label="Medical" value={payslip.medical} currency={cur} />
                            <Line label="Conveyance" value={payslip.conveyance} currency={cur} />
                            <Line label="Other Allowance" value={payslip.other_allowance} currency={cur} />
                            <Line label="Gross Earnings" value={payslip.gross_earnings} currency={cur} strong />
                        </div>
                    </div>
                    <div className="overflow-hidden rounded-xl border">
                        <div className="bg-rose-600 px-4 py-2 text-sm font-semibold uppercase tracking-wide text-white">Deductions</div>
                        <div className="divide-y text-sm">
                            <Line label="Income Tax" value={payslip.income_tax} currency={cur} />
                            <Line label="EOBI (employee)" value={payslip.eobi} currency={cur} />
                            <Line label="Provident Fund" value={payslip.provident_fund} currency={cur} />
                            <Line label="Other Deductions" value={payslip.other_deduction} currency={cur} />
                            <Line label="Total Deductions" value={payslip.total_deductions} currency={cur} strong />
                        </div>
                    </div>
                </div>

                {/* Loss of pay (only when the employee was docked) */}
                {payslip.loss_of_pay > 0 && (
                    <div className="mt-4 flex items-center justify-between rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm print:bg-amber-50">
                        <span className="font-medium text-amber-800">Less: Loss of Pay ({payslip.lop_days} day{payslip.lop_days === 1 ? '' : 's'})</span>
                        <span className="font-mono tabular-nums text-amber-800">− {money(payslip.loss_of_pay)} <span className="text-xs">{cur}</span></span>
                    </div>
                )}

                {/* Net pay */}
                <div className="mt-6 overflow-hidden rounded-xl text-white shadow-sm" style={gradient('#312e81', '#4f46e5')}>
                    <div className="flex items-center justify-between px-6 py-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Net Pay</div>
                            <div className="mt-0.5 text-xs text-indigo-100/90">{amountInWords(payslip.net_pay, cur === 'PKR' ? 'Rupees' : cur)}</div>
                        </div>
                        <div className="font-mono text-3xl font-bold tabular-nums">{money(payslip.net_pay)}</div>
                    </div>
                </div>

                {/* Employer contributions (informational) */}
                <div className="mt-6 rounded-xl border border-dashed p-4">
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Employer Contributions (not deducted from pay)</div>
                    <div className="grid grid-cols-2 gap-4 text-sm">
                        <div className="flex justify-between"><span className="text-slate-600">Employer EOBI</span><span className="font-mono tabular-nums">{money(payslip.employer_eobi)}</span></div>
                        <div className="flex justify-between"><span className="text-slate-600">Employer PF</span><span className="font-mono tabular-nums">{money(payslip.employer_pf)}</span></div>
                    </div>
                </div>

                {/* Footer */}
                <div className="mt-8 flex items-end justify-between border-t pt-4 text-xs text-slate-400">
                    <div>
                        Payment status: <span className="font-medium capitalize text-slate-600">{payslip.status}</span>
                        {payslip.paid_amount > 0 && <> · Paid {money(payslip.paid_amount)} {cur}</>}
                    </div>
                    <div className="text-right">
                        <div className="mb-6 border-b border-slate-300 pb-6" />
                        Authorised Signature
                    </div>
                </div>
                <p className="mt-4 text-center text-[10px] text-slate-400">This is a computer-generated payslip and does not require a physical signature unless printed for records.</p>
            </div>
        </div>
    );
}

function Field({ label, value, className = '' }: { label: string; value: string; className?: string }) {
    return (
        <div>
            <div className="text-[10px] uppercase tracking-wide text-slate-400">{label}</div>
            <div className={`font-medium ${className}`}>{value}</div>
        </div>
    );
}
