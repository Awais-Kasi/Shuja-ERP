import { Head, router, useForm } from '@inertiajs/react';
import { CalendarCheck, CalendarX, Plane } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Row = { employee_id: number; code: string; name: string; department: string | null; status: string; remarks: string | null };
type Summary = { present: number; absent: number; leave: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

const statusStyle: Record<string, string> = {
    present: 'text-emerald-600 dark:text-emerald-400',
    absent: 'text-rose-600 dark:text-rose-400',
    leave: 'text-amber-600 dark:text-amber-400',
    half_day: 'text-sky-600 dark:text-sky-400',
    holiday: 'text-muted-foreground',
};

export default function AttendanceIndex({
    date, rows, statuses, summary,
}: {
    date: string; rows: Row[]; statuses: string[]; summary: Summary;
}) {
    const form = useForm<{ date: string; rows: Row[] }>({ date, rows });

    const setStatus = (i: number, status: string) => form.setData('rows', form.data.rows.map((r, idx) => (idx === i ? { ...r, status } : r)));
    const setRemarks = (i: number, remarks: string) => form.setData('rows', form.data.rows.map((r, idx) => (idx === i ? { ...r, remarks } : r)));
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/hr/attendance', { preserveScroll: true }); };
    const changeDate = (d: string) => router.get('/hr/attendance', { date: d }, { preserveState: false });

    const tiles = [
        { label: 'Present', value: summary.present, icon: CalendarCheck, from: '#10b981', to: '#0f766e' },
        { label: 'On Leave', value: summary.leave, icon: Plane, from: '#f59e0b', to: '#c2410c' },
        { label: 'Absent', value: summary.absent, icon: CalendarX, from: '#f43f5e', to: '#be185d' },
    ];

    return (
        <>
            <Head title="Attendance" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#065f46', '#0d9488')}>
                    <CalendarCheck className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-emerald-200">People</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Daily Attendance</h1>
                            <p className="mt-1 text-sm text-emerald-100/80">Mark attendance and save the day’s register.</p>
                        </div>
                        <div className="flex items-end gap-3">
                            <div className="grid gap-1.5">
                                <label className="text-xs font-medium text-emerald-100/80">Date</label>
                                <Input type="date" value={form.data.date} onChange={(e) => changeDate(e.target.value)} className="bg-white/90 text-emerald-900" />
                            </div>
                            <Button type="submit" disabled={form.processing} className="bg-white text-emerald-700 hover:bg-white/90">Save register</Button>
                        </div>
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
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-28">Code</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th className="w-40">Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {form.data.rows.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No active employees.</td></tr>}
                            {form.data.rows.map((r, i) => (
                                <tr key={r.employee_id} className="hover:bg-muted/40 [&>td]:px-4 [&>td]:py-1.5">
                                    <td className="font-mono text-xs">{r.code}</td>
                                    <td className="font-medium">{r.name}</td>
                                    <td>{r.department ?? '—'}</td>
                                    <td>
                                        <Select value={r.status} onValueChange={(v) => setStatus(i, v)}>
                                            <SelectTrigger className={`w-full capitalize ${statusStyle[r.status] ?? ''}`}><SelectValue /></SelectTrigger>
                                            <SelectContent>{statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td><Input value={r.remarks ?? ''} onChange={(e) => setRemarks(i, e.target.value)} placeholder="—" /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </form>
        </>
    );
}
