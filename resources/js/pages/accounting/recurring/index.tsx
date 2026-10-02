import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Play, Plus, Repeat } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Template = {
    id: number; name: string; frequency: string; interval: number; next_run_date: string;
    end_date: string | null; status: string; amount: number; generated: number;
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export function scheduleLabel(freq: string, interval: number): string {
    const unit = { weekly: 'week', monthly: 'month', quarterly: 'quarter', yearly: 'year' }[freq] ?? freq;
    return interval > 1 ? `Every ${interval} ${unit}s` : { weekly: 'Weekly', monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly' }[freq] ?? freq;
}

const statusStyle: Record<string, string> = {
    active: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    paused: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    ended: 'bg-slate-500/15 text-slate-600 dark:text-slate-400',
};

export default function RecurringIndex({ templates, dueCount }: { templates: Template[]; dueCount: number }) {
    const { can } = usePermissions();
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;

    const runDue = () => router.post('/accounting/recurring/run', {}, { preserveScroll: true });

    return (
        <>
            <Head title="Recurring Journals" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#4338ca', '#6366f1')}>
                    <Repeat className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Accounting</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Recurring Journals</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">Schedule repeating entries — rent, depreciation accruals, amortisation — and post them on time.</p>
                        </div>
                        {can('accounting.recurring.manage') && (
                            <div className="flex items-center gap-2">
                                <Button variant="secondary" className="bg-white/15 text-white hover:bg-white/25 disabled:opacity-50" disabled={dueCount === 0} onClick={runDue}>
                                    <Play className="size-4" /> Run due{dueCount > 0 ? ` (${dueCount})` : ''}
                                </Button>
                                <Button className="bg-white text-indigo-700 hover:bg-white/90" onClick={() => router.visit('/accounting/recurring/create')}><Plus className="size-4" /> New template</Button>
                            </div>
                        )}
                    </div>
                </div>

                {errors.run && (
                    <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-400">
                        <AlertTriangle className="size-4" /> {errors.run}
                    </div>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[820px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Template</th>
                                <th className="text-left">Schedule</th>
                                <th className="text-left">Next run</th>
                                <th className="text-right">Amount</th>
                                <th className="text-center">Posted</th>
                                <th className="text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {templates.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No recurring journals yet.</td></tr>}
                            {templates.map((t) => (
                                <tr key={t.id} onClick={() => router.visit(`/accounting/recurring/${t.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{t.name}</td>
                                    <td>{scheduleLabel(t.frequency, t.interval)}</td>
                                    <td className="font-mono text-xs">{t.status === 'ended' ? '—' : t.next_run_date}</td>
                                    <td className="text-right font-mono tabular-nums">{money(t.amount)}</td>
                                    <td className="text-center tabular-nums">{t.generated}</td>
                                    <td><Badge variant="secondary" className={`capitalize ${statusStyle[t.status] ?? ''}`}>{t.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
