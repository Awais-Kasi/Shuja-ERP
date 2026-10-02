import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, Lock, LockOpen, RotateCcw } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Period = { id: number; name: string; starts_on: string; ends_on: string; status: string };
type Year = { id: number; name: string; starts_on: string; ends_on: string; status: string; closed_at: string | null; net_profit: number; periods: Period[] };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function PeriodsIndex({ years }: { years: Year[] }) {
    const { can } = usePermissions();
    const canLock = can('accounting.period.lock');
    const canClose = can('accounting.period.close');
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;

    const lock = (p: Period) => router.post(`/accounting/periods/${p.id}/${p.status === 'locked' ? 'unlock' : 'lock'}`, {}, { preserveScroll: true });
    const closeYear = (y: Year) => { if (confirm(`Close ${y.name}? This posts the year-end closing entry to Retained Earnings and locks every period.`)) router.post(`/accounting/fiscal-years/${y.id}/close`, {}, { preserveScroll: true }); };
    const reopenYear = (y: Year) => { if (confirm(`Reopen ${y.name}? This reverses the closing entry and unlocks its periods.`)) router.post(`/accounting/fiscal-years/${y.id}/reopen`, {}, { preserveScroll: true }); };

    return (
        <>
            <Head title="Period Close" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#334155', '#0f172a')}>
                    <CalendarClock className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative">
                        <div className="text-xs font-medium uppercase tracking-widest text-slate-300">Accounting · Controls</div>
                        <h1 className="mt-1 text-3xl font-bold tracking-tight">Period Close</h1>
                        <p className="mt-1 text-sm text-slate-300/90">Lock periods against further posting and run the year-end close to Retained Earnings.</p>
                    </div>
                </div>

                {(errors.close || errors.period) && (
                    <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-400"><AlertTriangle className="size-4" /> {errors.close || errors.period}</div>
                )}

                {years.map((y) => (
                    <div key={y.id} className="rounded-2xl border">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/30 px-5 py-3">
                            <div className="flex items-center gap-3">
                                <h2 className="text-lg font-semibold">{y.name}</h2>
                                <Badge variant="secondary" className={y.status === 'closed' ? 'bg-slate-500/15 text-slate-600 dark:text-slate-300' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'}>
                                    <span className="capitalize">{y.status}</span>
                                </Badge>
                                <span className="text-muted-foreground text-xs">{y.starts_on} → {y.ends_on}</span>
                            </div>
                            <div className="flex items-center gap-3">
                                <div className="text-right">
                                    <div className="text-muted-foreground text-[10px] uppercase tracking-wide">Net {y.net_profit >= 0 ? 'profit' : 'loss'}</div>
                                    <div className={`font-mono text-sm font-semibold tabular-nums ${y.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>{money(Math.abs(y.net_profit))}</div>
                                </div>
                                {canClose && y.status !== 'closed' && <Button size="sm" onClick={() => closeYear(y)}><Lock className="size-4" /> Close year</Button>}
                                {canClose && y.status === 'closed' && <Button size="sm" variant="outline" onClick={() => reopenYear(y)}><RotateCcw className="size-4" /> Reopen year</Button>}
                            </div>
                        </div>
                        <div className="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {y.periods.map((p) => (
                                <div key={p.id} className="flex items-center justify-between rounded-lg border bg-card px-3 py-2">
                                    <div>
                                        <div className="text-sm font-medium">{p.name}</div>
                                        <div className="text-muted-foreground text-[11px]">{p.starts_on} → {p.ends_on}</div>
                                    </div>
                                    {p.status === 'locked'
                                        ? (canLock && y.status !== 'closed'
                                            ? <Button size="icon" variant="ghost" title="Reopen period" onClick={() => lock(p)}><Lock className="size-4 text-rose-500" /></Button>
                                            : <Lock className="text-muted-foreground/50 size-4" title="Locked" />)
                                        : (canLock
                                            ? <Button size="icon" variant="ghost" title="Lock period" onClick={() => lock(p)}><LockOpen className="size-4 text-emerald-500" /></Button>
                                            : <LockOpen className="text-muted-foreground/50 size-4" title="Open" />)}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </>
    );
}
