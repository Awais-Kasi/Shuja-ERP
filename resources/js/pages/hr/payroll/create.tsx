import { Head, useForm } from '@inertiajs/react';
import { Users, Wallet } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type MonthOption = { value: number; label: string };
type Defaults = { period_year: number; period_month: number; accrual_date: string };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function PayrollCreate({
    months, defaults, activeEmployees,
}: {
    months: MonthOption[]; defaults: Defaults; activeEmployees: number;
}) {
    const form = useForm({
        period_year: String(defaults.period_year),
        period_month: String(defaults.period_month),
        accrual_date: defaults.accrual_date,
        memo: '',
    });

    const periodError = (form.errors as Record<string, string>).period;
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/hr/payroll'); };

    return (
        <>
            <Head title="Run Payroll" />
            <form onSubmit={submit} className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#312e81', '#4f46e5')}>
                    <Wallet className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative">
                        <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Payroll</div>
                        <h1 className="mt-1 text-3xl font-bold tracking-tight">Run Payroll</h1>
                        <p className="mt-1 flex items-center gap-1.5 text-sm text-indigo-100/80">
                            <Users className="size-4" /> {activeEmployees} active {activeEmployees === 1 ? 'employee' : 'employees'} will be included.
                        </p>
                    </div>
                </div>

                {periodError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{periodError}</div>}

                <div className="bg-card grid gap-4 rounded-2xl border p-5 shadow-sm sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label>Month</Label>
                        <Select value={form.data.period_month} onValueChange={(v) => form.setData('period_month', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{months.map((m) => <SelectItem key={m.value} value={String(m.value)}>{m.label}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.period_month && <p className="text-destructive text-xs">{form.errors.period_month}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="year">Year</Label>
                        <Input id="year" inputMode="numeric" value={form.data.period_year} onChange={(e) => form.setData('period_year', e.target.value)} className="font-mono" />
                        {form.errors.period_year && <p className="text-destructive text-xs">{form.errors.period_year}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="accrual">Accrual date</Label>
                        <Input id="accrual" type="date" value={form.data.accrual_date} onChange={(e) => form.setData('accrual_date', e.target.value)} />
                        {form.errors.accrual_date && <p className="text-destructive text-xs">{form.errors.accrual_date}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="memo">Memo</Label>
                        <Input id="memo" value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="Optional" />
                    </div>
                </div>

                <div className="flex items-center justify-between">
                    <p className="text-muted-foreground text-xs">A draft run is created with one payslip per employee. Review it, then post the accrual.</p>
                    <Button type="submit" disabled={form.processing}>Create draft</Button>
                </div>
            </form>
        </>
    );
}
