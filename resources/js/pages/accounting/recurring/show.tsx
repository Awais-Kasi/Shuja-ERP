import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Pause, Play, Repeat, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';
import { scheduleLabel } from './index';

type Line = { account: string; cost_center: string | null; description: string | null; debit: number; credit: number };
type Generated = { id: number; number: string; entry_date: string };
type Template = {
    id: number; name: string; reference: string | null; memo: string | null; frequency: string; interval: number;
    start_date: string; next_run_date: string; end_date: string | null; status: string; last_generated_at: string | null; lines: Line[];
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

const statusStyle: Record<string, string> = {
    active: 'bg-white/20 text-white', paused: 'bg-white/20 text-white', ended: 'bg-white/20 text-white',
};

export default function RecurringShow({ template, generated }: { template: Template; generated: Generated[] }) {
    const { can } = usePermissions();
    const manage = can('accounting.recurring.manage');
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;

    const totalDebit = template.lines.reduce((s, l) => s + l.debit, 0);
    const post = (verb: string) => router.post(`/accounting/recurring/${template.id}/${verb}`, {}, { preserveScroll: true });
    const remove = () => { if (confirm('Delete this template? Journals already posted are kept.')) router.delete(`/accounting/recurring/${template.id}`); };

    const facts = [
        ['Schedule', scheduleLabel(template.frequency, template.interval)],
        ['Next run', template.status === 'ended' ? '—' : template.next_run_date],
        ['Start', template.start_date],
        ['End', template.end_date ?? 'No end'],
        ['Last generated', template.last_generated_at ?? 'Never'],
    ];

    return (
        <>
            <Head title={`Recurring — ${template.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit('/accounting/recurring')}><ArrowLeft className="size-4" /> All templates</Button>
                    <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#4338ca', '#6366f1')}>
                        <Repeat className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                        <div className="relative flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Recurring journal</div>
                                <h1 className="mt-1 text-2xl font-bold tracking-tight">{template.name}</h1>
                                {template.memo && <p className="mt-1 text-sm text-indigo-100/80">{template.memo}</p>}
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge variant="secondary" className={statusStyle[template.status]}><span className="capitalize">{template.status}</span></Badge>
                                {manage && template.status === 'active' && <Button className="bg-white text-indigo-700 hover:bg-white/90" onClick={() => post('run')}><Play className="size-4" /> Run now</Button>}
                                {manage && template.status === 'active' && <Button variant="secondary" className="bg-white/15 text-white hover:bg-white/25" onClick={() => post('pause')}><Pause className="size-4" /> Pause</Button>}
                                {manage && template.status === 'paused' && <Button className="bg-white text-indigo-700 hover:bg-white/90" onClick={() => post('resume')}><Play className="size-4" /> Resume</Button>}
                                {manage && <Button variant="secondary" className="bg-white/15 text-white hover:bg-white/25" onClick={remove}><Trash2 className="size-4" /></Button>}
                            </div>
                        </div>
                    </div>
                </div>

                {errors.run && <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-400"><AlertTriangle className="size-4" /> {errors.run}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {facts.map(([label, value]) => (
                        <div key={label} className="rounded-xl border bg-card p-3">
                            <div className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{label}</div>
                            <div className="mt-1 text-sm font-semibold">{value}</div>
                        </div>
                    ))}
                </div>

                <div>
                    <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Template lines</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                    <th className="text-left">Account</th><th className="text-left">Description</th><th className="text-left">Cost center</th>
                                    <th className="text-right">Debit</th><th className="text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {template.lines.map((l, i) => (
                                    <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5">
                                        <td className="font-medium">{l.account}</td>
                                        <td className="text-muted-foreground">{l.description ?? '—'}</td>
                                        <td className="text-muted-foreground text-xs">{l.cost_center ?? '—'}</td>
                                        <td className="text-right font-mono tabular-nums">{l.debit ? money(l.debit) : '—'}</td>
                                        <td className="text-right font-mono tabular-nums">{l.credit ? money(l.credit) : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t-2">
                                <tr className="[&>td]:px-4 [&>td]:py-2.5 font-semibold">
                                    <td colSpan={3}>Total</td>
                                    <td className="text-right font-mono tabular-nums">{money(totalDebit)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(totalDebit)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div>
                    <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Posted journals ({generated.length})</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[420px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Journal</th><th className="text-left">Date</th></tr>
                            </thead>
                            <tbody className="divide-y">
                                {generated.length === 0 && <tr><td colSpan={2} className="text-muted-foreground px-4 py-8 text-center">None posted yet.</td></tr>}
                                {generated.map((g) => (
                                    <tr key={g.id} onClick={() => router.visit(`/accounting/journals/${g.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2.5">
                                        <td className="font-mono text-xs text-indigo-600">{g.number}</td>
                                        <td className="font-mono text-xs">{g.entry_date}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}
