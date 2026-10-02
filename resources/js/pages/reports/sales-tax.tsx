import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Line = { type: string; number: string | null; date: string; party: string | null; taxable: number; tax: number };
type Summary = {
    taxable_sales: number; output_tax: number; taxable_purchases: number; input_tax: number; net_payable: number;
    ledger_output_tax: number; ledger_input_tax: number;
};
type Report = { from: string; to: string; summary: Summary; output: Line[]; input: Line[] };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

function Register({ title, lines }: { title: string; lines: Line[] }) {
    const taxable = lines.reduce((s, l) => s + l.taxable, 0);
    const tax = lines.reduce((s, l) => s + l.tax, 0);
    return (
        <div>
            <h2 className="mb-2 text-sm font-semibold text-muted-foreground">{title}</h2>
            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[640px] text-sm">
                    <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Type</th><th className="text-left">Number</th><th className="text-left">Date</th><th className="text-left">Party</th><th className="text-right">Taxable</th><th className="text-right">Tax</th></tr></thead>
                    <tbody className="divide-y">
                        {lines.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">No taxed documents in this period.</td></tr>}
                        {lines.map((l, i) => (
                            <tr key={i} className="[&>td]:px-4 [&>td]:py-2">
                                <td className="text-muted-foreground text-xs">{l.type}</td>
                                <td className="font-mono text-xs">{l.number}</td>
                                <td className="font-mono text-xs">{l.date}</td>
                                <td>{l.party ?? '—'}</td>
                                <td className={`text-right font-mono tabular-nums ${l.taxable < 0 ? 'text-rose-600' : ''}`}>{money(l.taxable)}</td>
                                <td className={`text-right font-mono tabular-nums ${l.tax < 0 ? 'text-rose-600' : ''}`}>{money(l.tax)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t-2 font-semibold"><tr className="[&>td]:px-4 [&>td]:py-2.5"><td colSpan={4} className="text-right">Total</td><td className="text-right font-mono tabular-nums">{money(taxable)}</td><td className="text-right font-mono tabular-nums">{money(tax)}</td></tr></tfoot>
                </table>
            </div>
        </div>
    );
}

export default function SalesTax({ report, filters }: { report: Report; filters: { from: string; to: string } }) {
    const s = report.summary;
    const setRange = (patch: Partial<{ from: string; to: string }>) => router.get('/reports/sales-tax', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });
    const outputTies = Math.abs(s.output_tax - s.ledger_output_tax) < 0.01;
    const inputTies = Math.abs(s.input_tax - s.ledger_input_tax) < 0.01;

    return (
        <>
            <Head title="Sales Tax Return" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#0f766e', '#065f46')}>
                    <div className="relative flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-teal-100">Finance · Compliance</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Sales Tax Return</h1>
                            <p className="mt-1 text-sm text-teal-50/80">Output tax on sales less input tax on purchases, {report.from} → {report.to}.</p>
                        </div>
                        <div className="flex gap-3">
                            <div className="grid gap-1.5"><Label className="text-xs text-teal-50">From</Label><Input type="date" value={filters.from} onChange={(e) => setRange({ from: e.target.value })} className="w-40 bg-white/90 text-slate-900" /></div>
                            <div className="grid gap-1.5"><Label className="text-xs text-teal-50">To</Label><Input type="date" value={filters.to} onChange={(e) => setRange({ to: e.target.value })} className="w-40 bg-white/90 text-slate-900" /></div>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient('#10b981', '#0f766e')}>
                        <div className="text-xs font-medium uppercase tracking-wide text-white/80">Output tax (sales)</div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(s.output_tax)}</div>
                        <div className="mt-1 text-xs text-white/80">on {money(s.taxable_sales)} taxable</div>
                    </div>
                    <div className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient('#f59e0b', '#b45309')}>
                        <div className="text-xs font-medium uppercase tracking-wide text-white/80">Input tax (purchases)</div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(s.input_tax)}</div>
                        <div className="mt-1 text-xs text-white/80">on {money(s.taxable_purchases)} taxable</div>
                    </div>
                    <div className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(s.net_payable >= 0 ? '#4338ca' : '#0891b2', s.net_payable >= 0 ? '#312e81' : '#0e7490')}>
                        <div className="text-xs font-medium uppercase tracking-wide text-white/80">{s.net_payable >= 0 ? 'Net tax payable' : 'Net tax refundable'}</div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(Math.abs(s.net_payable))}</div>
                        <div className="mt-1 text-xs text-white/80">output − input</div>
                    </div>
                </div>

                <div className={`flex flex-wrap items-center gap-4 rounded-lg border px-4 py-3 text-sm ${outputTies && inputTies ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-400' : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-400'}`}>
                    {outputTies && inputTies ? <CheckCircle2 className="size-4" /> : <AlertTriangle className="size-4" />}
                    <span>Ledger check — output tax account {money(s.ledger_output_tax)} ({outputTies ? 'ties' : 'differs'}), input tax account {money(s.ledger_input_tax)} ({inputTies ? 'ties' : 'differs'}).</span>
                </div>

                <Register title="Output tax — sales & credit notes" lines={report.output} />
                <Register title="Input tax — purchases & debit notes" lines={report.input} />
            </div>
        </>
    );
}
