import { Head, useForm } from '@inertiajs/react';
import { BadgeDollarSign, Plus, UserCheck, Users, type LucideIcon } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type Employee = {
    id: number; code: string; name: string; designation: string | null; department: string | null;
    employment_type: string; payment_method: string; cost_center: string | null; gross: number; is_active: boolean;
};
type Summary = { total: number; active: number; monthly_gross: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

const typeStyle: Record<string, string> = {
    salaried: 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400',
    wage: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
};

export default function EmployeesIndex({
    employees, costCenters, salaryAccounts, summary,
}: {
    employees: Employee[]; costCenters: Option[]; salaryAccounts: Option[]; summary: Summary;
}) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);

    const tiles: { label: string; value: string; icon: LucideIcon; from: string; to: string }[] = [
        { label: 'Employees', value: String(summary.total), icon: Users, from: '#8b5cf6', to: '#6d28d9' },
        { label: 'Active', value: String(summary.active), icon: UserCheck, from: '#10b981', to: '#0f766e' },
        { label: 'Monthly Gross', value: money(summary.monthly_gross), icon: BadgeDollarSign, from: '#f59e0b', to: '#c2410c' },
    ];

    return (
        <>
            <Head title="Employees" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#4c1d95', '#7c3aed')}>
                    <Users className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-violet-200">People</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Employees</h1>
                            <p className="mt-1 text-sm text-violet-100/80">Salary structures feed payroll accruals & statutory deductions.</p>
                        </div>
                        {can('hr.employee.manage') && (
                            <Dialog open={open} onOpenChange={setOpen}>
                                <DialogTrigger asChild>
                                    <Button className="bg-white text-violet-700 hover:bg-white/90"><Plus className="size-4" /> New employee</Button>
                                </DialogTrigger>
                                <EmployeeDialog costCenters={costCenters} salaryAccounts={salaryAccounts} onDone={() => setOpen(false)} />
                            </Dialog>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    {tiles.map((t) => (
                        <div key={t.label} className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(t.from, t.to)}>
                            <t.icon className="absolute -bottom-3 -right-3 size-20 opacity-20" />
                            <div className="relative">
                                <div className="text-xs font-medium uppercase tracking-wide text-white/80">{t.label}</div>
                                <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{t.value}</div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-28">Code</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>Department</th>
                                <th className="w-24">Type</th>
                                <th className="w-24">Cost Center</th>
                                <th className="w-32 text-right">Gross</th>
                                <th className="w-20">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {employees.length === 0 && <tr><td colSpan={8} className="text-muted-foreground px-4 py-10 text-center">No employees yet.</td></tr>}
                            {employees.map((e) => (
                                <tr key={e.id} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs">{e.code}</td>
                                    <td className="px-4 py-2.5 font-medium">{e.name}</td>
                                    <td className="px-4 py-2.5">{e.designation ?? '—'}</td>
                                    <td className="px-4 py-2.5">{e.department ?? '—'}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className={`capitalize ${typeStyle[e.employment_type] ?? ''}`}>{e.employment_type}</Badge></td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{e.cost_center ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(e.gross)}</td>
                                    <td className="px-4 py-2.5">
                                        {e.is_active
                                            ? <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"><span className="size-1.5 rounded-full bg-emerald-500" /> Active</span>
                                            : <span className="text-muted-foreground text-xs">Inactive</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function EmployeeDialog({ costCenters, salaryAccounts, onDone }: { costCenters: Option[]; salaryAccounts: Option[]; onDone: () => void }) {
    const form = useForm({
        code: '', name: '', designation: '', department: '',
        employment_type: 'salaried', payment_method: 'bank',
        cost_center_id: '', salary_expense_account_id: '',
        cnic: '', eobi_no: '', bank_name: '', bank_account_no: '', phone: '',
        basic_salary: '', house_rent: '', medical: '', conveyance: '', other_allowance: '',
        date_joined: '', is_active: true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/hr/employees', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };

    const field = (key: keyof typeof form.data, label: string, props: Record<string, unknown> = {}) => (
        <div className="grid gap-1.5">
            <Label htmlFor={key}>{label}</Label>
            <Input id={key} value={form.data[key] as string} onChange={(ev) => form.setData(key, ev.target.value)} {...props} />
            {form.errors[key] && <p className="text-destructive text-xs">{form.errors[key]}</p>}
        </div>
    );

    return (
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader><DialogTitle>New employee</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    {field('code', 'Code', { placeholder: 'EMP-006' })}
                    {field('name', 'Full name', { placeholder: 'Employee name' })}
                    {field('designation', 'Designation')}
                    {field('department', 'Department')}
                    <div className="grid gap-1.5">
                        <Label>Employment type</Label>
                        <Select value={form.data.employment_type} onValueChange={(v) => form.setData('employment_type', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value="salaried">Salaried</SelectItem><SelectItem value="wage">Wage</SelectItem></SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Payment method</Label>
                        <Select value={form.data.payment_method} onValueChange={(v) => form.setData('payment_method', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value="bank">Bank</SelectItem><SelectItem value="cash">Cash</SelectItem></SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Cost center</Label>
                        <Select value={form.data.cost_center_id} onValueChange={(v) => form.setData('cost_center_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                            <SelectContent>{costCenters.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.code} — {c.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Salary expense account</Label>
                        <Select value={form.data.salary_expense_account_id} onValueChange={(v) => form.setData('salary_expense_account_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Default 6100" /></SelectTrigger>
                            <SelectContent>{salaryAccounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="text-muted-foreground text-xs font-semibold uppercase tracking-wide">Salary structure (monthly)</div>
                <div className="grid gap-4 sm:grid-cols-3">
                    {field('basic_salary', 'Basic', { inputMode: 'decimal', className: 'text-right font-mono' })}
                    {field('house_rent', 'House rent', { inputMode: 'decimal', className: 'text-right font-mono' })}
                    {field('medical', 'Medical', { inputMode: 'decimal', className: 'text-right font-mono' })}
                    {field('conveyance', 'Conveyance', { inputMode: 'decimal', className: 'text-right font-mono' })}
                    {field('other_allowance', 'Other allowance', { inputMode: 'decimal', className: 'text-right font-mono' })}
                    {field('date_joined', 'Date joined', { type: 'date' })}
                </div>

                <div className="text-muted-foreground text-xs font-semibold uppercase tracking-wide">Identity & bank</div>
                <div className="grid gap-4 sm:grid-cols-3">
                    {field('cnic', 'CNIC')}
                    {field('eobi_no', 'EOBI no.')}
                    {field('phone', 'Phone')}
                    {field('bank_name', 'Bank name')}
                    {field('bank_account_no', 'Bank account no.')}
                </div>

                <DialogFooter>
                    <Button type="submit" disabled={form.processing}>Save employee</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
