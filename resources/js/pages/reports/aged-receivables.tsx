import { Head, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Row = { party: string; current: number; d1_30: number; d31_60: number; d61_90: number; d90_plus: number; total: number; currencies?: string };
type Report = { as_of: string; base_currency?: string; has_foreign?: boolean; rows: Row[]; totals: Omit<Row, 'party'> };

const COLS: { key: keyof Omit<Row, 'party'>; label: string }[] = [
    { key: 'current', label: 'Current' }, { key: 'd1_30', label: '1–30' }, { key: 'd31_60', label: '31–60' },
    { key: 'd61_90', label: '61–90' }, { key: 'd90_plus', label: '90+' }, { key: 'total', label: 'Total' },
];

export function AgingReport({ title, subtitle, partyLabel, report }: { title: string; subtitle: string; partyLabel: string; report: Report }) {
    const setAsOf = (v: string) => router.get(window.location.pathname, { as_of: v }, { preserveState: true, preserveScroll: true, replace: true });
    return (
        <>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                        <p className="text-muted-foreground text-sm">{subtitle} as of {report.as_of}{report.base_currency ? ` · amounts in ${report.base_currency}` : ''}</p>
                    </div>
                    <div className="grid gap-1.5"><Label className="text-xs">As of date</Label><Input type="date" value={report.as_of} onChange={(e) => setAsOf(e.target.value)} className="w-44" /></div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">{partyLabel}</th>
                                {COLS.map((c) => <th key={c.key} className="text-right">{c.label}</th>)}
                                {report.has_foreign && <th className="text-right">FX exposure</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {report.rows.length === 0 && <tr><td colSpan={report.has_foreign ? 8 : 7} className="text-muted-foreground px-4 py-10 text-center">Nothing outstanding.</td></tr>}
                            {report.rows.map((r, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{r.party}</td>
                                    {COLS.map((c) => <td key={c.key} className={`text-right font-mono tabular-nums ${c.key === 'total' ? 'font-semibold' : ''} ${c.key === 'd90_plus' && r.d90_plus > 0 ? 'text-rose-600' : ''}`}>{r[c.key] ? money(r[c.key]) : '—'}</td>)}
                                    {report.has_foreign && <td className="text-muted-foreground text-right font-mono text-xs tabular-nums">{r.currencies || '—'}</td>}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3">
                                <td>Total</td>
                                {COLS.map((c) => <td key={c.key} className="text-right font-mono tabular-nums">{money(report.totals[c.key])}</td>)}
                                {report.has_foreign && <td />}
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}

export default function AgedReceivables({ report }: { report: Report }) {
    return <AgingReport title="Aged Receivables" subtitle="Outstanding customer balances by age" partyLabel="Customer" report={report} />;
}
