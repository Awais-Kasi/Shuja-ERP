import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Banknote, Landmark, PiggyBank, Receipt, type LucideIcon } from 'lucide-react';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; period: string; gross: number; income_tax: number; eobi: number; pf: number; net: number };
type Totals = { gross: number; income_tax: number; eobi: number; pf: number; net: number };
type Outstanding = { income_tax: number; eobi: number; pf: number; wages_payable: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function StatutoryReport({ outstanding, rows, totals }: { outstanding: Outstanding; rows: Row[]; totals: Totals }) {
    const tiles: { label: string; value: number; icon: LucideIcon; from: string; to: string }[] = [
        { label: 'Income Tax Payable', value: outstanding.income_tax, icon: Receipt, from: '#6366f1', to: '#4338ca' },
        { label: 'EOBI Payable', value: outstanding.eobi, icon: Landmark, from: '#0ea5e9', to: '#2563eb' },
        { label: 'Provident Fund Payable', value: outstanding.pf, icon: PiggyBank, from: '#10b981', to: '#0f766e' },
        { label: 'Wages Payable', value: outstanding.wages_payable, icon: Banknote, from: '#f59e0b', to: '#c2410c' },
    ];

    return (
        <>
            <Head title="Statutory Report" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Link href="/hr/payroll" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm"><ArrowLeft className="size-4" /> Back to payroll</Link>
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight">Statutory &amp; Payroll Report</h1>
                    <p className="text-muted-foreground text-sm">Outstanding statutory liabilities to deposit, and the payroll register by period.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {tiles.map((t) => (
                        <div key={t.label} className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(t.from, t.to)}>
                            <t.icon className="absolute -bottom-3 -right-3 size-20 opacity-20" />
                            <div className="relative">
                                <div className="text-xs font-medium uppercase tracking-wide text-white/80">{t.label}</div>
                                <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(t.value)}</div>
                                <div className="text-[11px] font-medium text-white/70">Outstanding</div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Run</th>
                                <th className="text-left">Period</th>
                                <th className="text-right">Gross</th>
                                <th className="text-right">Income Tax</th>
                                <th className="text-right">EOBI (total)</th>
                                <th className="text-right">PF (total)</th>
                                <th className="text-right">Net Paid</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.length === 0 && <tr><td colSpan={7} className="text-muted-foreground px-4 py-10 text-center">No posted payroll runs yet.</td></tr>}
                            {rows.map((r) => (
                                <tr key={r.id} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs"><Link href={`/hr/payroll/${r.id}`} className="text-indigo-600 hover:underline dark:text-indigo-400">{r.number ?? '—'}</Link></td>
                                    <td className="px-4 py-2.5">{r.period}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.gross)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.income_tax)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.eobi)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.pf)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.net)}</td>
                                </tr>
                            ))}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot className="bg-muted/30 font-semibold">
                                <tr className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td colSpan={2} className="text-left">Total ({rows.length})</td>
                                    <td className="text-right font-mono tabular-nums">{money(totals.gross)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(totals.income_tax)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(totals.eobi)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(totals.pf)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(totals.net)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">EOBI &amp; PF totals include both employee and employer shares. Outstanding balances are the current credit balances of accounts 2141 (income tax), 2142 (EOBI), 2143 (PF) and 2140 (net wages) — what remains to be deposited/disbursed.</p>
            </div>
        </>
    );
}
