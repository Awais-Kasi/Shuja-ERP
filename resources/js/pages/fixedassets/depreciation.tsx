import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Play, TrendingDown } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { money } from '@/lib/format';

type Run = { id: number; period: string; run_date: string; assets: number; total: number; journal_id: number | null };
type MonthOption = { value: number; label: string };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function Depreciation({ runs, months, defaults, activeAssets }: {
    runs: Run[]; months: MonthOption[]; defaults: { period_year: number; period_month: number }; activeAssets: number;
}) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const form = useForm({ period_year: String(defaults.period_year), period_month: String(defaults.period_month) });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/assets/depreciation', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Depreciation" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <Link href="/assets" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm"><ArrowLeft className="size-4" /> Fixed assets</Link>

                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#312e81', '#4f46e5')}>
                    <TrendingDown className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative">
                        <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Finance</div>
                        <h1 className="mt-1 text-3xl font-bold tracking-tight">Depreciation</h1>
                        <p className="mt-1 text-sm text-indigo-100/80">Post one month's straight-line depreciation across {activeAssets} active {activeAssets === 1 ? 'asset' : 'assets'}.</p>
                    </div>
                </div>

                {errors.depreciation && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.depreciation}</div>}

                <form onSubmit={submit} className="bg-card flex flex-wrap items-end gap-4 rounded-2xl border p-5 shadow-sm">
                    <div className="grid gap-1.5">
                        <Label>Month</Label>
                        <Select value={form.data.period_month} onValueChange={(v) => form.setData('period_month', v)}>
                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                            <SelectContent>{months.map((m) => <SelectItem key={m.value} value={String(m.value)}>{m.label}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="year">Year</Label>
                        <Input id="year" inputMode="numeric" value={form.data.period_year} onChange={(e) => form.setData('period_year', e.target.value)} className="w-28 font-mono" />
                    </div>
                    <Button type="submit" disabled={form.processing}><Play className="size-4" /> Run depreciation</Button>
                </form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Period</th>
                                <th className="text-left">Run date</th>
                                <th className="text-right">Assets</th>
                                <th className="text-right">Total charge</th>
                                <th className="text-left">Journal</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {runs.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No depreciation runs yet.</td></tr>}
                            {runs.map((r) => (
                                <tr key={r.id} className="hover:bg-muted/40 [&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{r.period}</td>
                                    <td className="tabular-nums">{r.run_date}</td>
                                    <td className="text-right tabular-nums">{r.assets}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.total)}</td>
                                    <td className="text-xs">{r.journal_id && <button type="button" onClick={() => router.visit(`/accounting/journals/${r.journal_id}`)} className="text-indigo-600 hover:underline dark:text-indigo-400">view</button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
