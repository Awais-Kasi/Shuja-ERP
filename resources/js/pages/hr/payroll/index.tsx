import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, FileBarChart, Plus, SlidersHorizontal, Wallet, Layers, type LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Run = { id: number; number: string | null; period: string; status: string; employees: number; gross_total: number; net_total: number };
type Summary = { runs: number; posted: number; net_ytd: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

const statusStyle: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    posted: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    partially_paid: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    paid: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    reversed: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
};

export default function PayrollIndex({ runs, summary }: { runs: Run[]; summary: Summary }) {
    const { can } = usePermissions();

    const tiles: { label: string; value: string; icon: LucideIcon; from: string; to: string }[] = [
        { label: 'Payroll Runs', value: String(summary.runs), icon: Layers, from: '#6366f1', to: '#4338ca' },
        { label: 'Posted', value: String(summary.posted), icon: CheckCircle2, from: '#10b981', to: '#0f766e' },
        { label: 'Net Paid / Payable', value: money(summary.net_ytd), icon: Wallet, from: '#f59e0b', to: '#c2410c' },
    ];

    return (
        <>
            <Head title="Payroll" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#312e81', '#4f46e5')}>
                    <Wallet className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Payroll</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Payroll Runs</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">Accrue monthly salaries with statutory deductions, then disburse.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button asChild variant="secondary" className="bg-white/15 text-white hover:bg-white/25">
                                <Link href="/hr/payroll/statutory-report"><FileBarChart className="size-4" /> Report</Link>
                            </Button>
                            {can('hr.payroll.configure') && (
                                <Button asChild variant="secondary" className="bg-white/15 text-white hover:bg-white/25">
                                    <Link href="/hr/payroll/settings"><SlidersHorizontal className="size-4" /> Settings</Link>
                                </Button>
                            )}
                            {can('hr.payroll.run') && (
                                <Button asChild className="bg-white text-indigo-700 hover:bg-white/90">
                                    <Link href="/hr/payroll/create"><Plus className="size-4" /> Run payroll</Link>
                                </Button>
                            )}
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
                                <th className="w-32">Number</th>
                                <th>Period</th>
                                <th className="w-24 text-right">Staff</th>
                                <th className="w-36 text-right">Gross</th>
                                <th className="w-36 text-right">Net</th>
                                <th className="w-28">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {runs.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No payroll runs yet.</td></tr>}
                            {runs.map((r) => (
                                <tr key={r.id} onClick={() => router.visit(`/hr/payroll/${r.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{r.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 font-medium">{r.period}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{r.employees}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.gross_total)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.net_total)}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className={`capitalize ${statusStyle[r.status] ?? ''}`}>{r.status.replace('_', ' ')}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
