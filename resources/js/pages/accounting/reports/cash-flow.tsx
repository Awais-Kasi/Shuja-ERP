import { Head, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Line = { code: string; name: string; amount: number };
type Section = { rows: Line[]; total: number };
type Report = {
    from: string; to: string;
    operating: Section; investing: Section; financing: Section;
    net_change: number; opening_cash: number; closing_cash: number;
};

function SectionBlock({ title, section }: { title: string; section: Section }) {
    return (
        <>
            <tr className="bg-muted/40"><td colSpan={2} className="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</td></tr>
            {section.rows.length === 0 && <tr><td colSpan={2} className="text-muted-foreground px-4 py-2 text-center text-xs">No movement</td></tr>}
            {section.rows.map((r) => (
                <tr key={r.code + r.name} className="[&>td]:px-4 [&>td]:py-1.5">
                    <td><span className="text-muted-foreground mr-2 font-mono text-xs">{r.code}</span>{r.name}</td>
                    <td className={`text-right font-mono tabular-nums ${r.amount < 0 ? 'text-rose-600' : 'text-emerald-600'}`}>{money(r.amount)}</td>
                </tr>
            ))}
            <tr className="border-t [&>td]:px-4 [&>td]:py-1.5 font-medium"><td className="text-right text-muted-foreground">Net cash from {title.toLowerCase()}</td><td className="text-right font-mono tabular-nums">{money(section.total)}</td></tr>
        </>
    );
}

export default function CashFlow({ report, filters }: { report: Report; filters: { from: string; to: string } }) {
    const setRange = (patch: Partial<{ from: string; to: string }>) =>
        router.get('/accounting/cash-flow', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Cash Flow" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Cash Flow Statement</h1>
                        <p className="text-muted-foreground text-sm">Movement in cash &amp; equivalents, {report.from} → {report.to}</p>
                    </div>
                    <div className="flex gap-3">
                        <div className="grid gap-1.5"><Label className="text-xs">From</Label><Input type="date" value={filters.from} onChange={(e) => setRange({ from: e.target.value })} className="w-40" /></div>
                        <div className="grid gap-1.5"><Label className="text-xs">To</Label><Input type="date" value={filters.to} onChange={(e) => setRange({ to: e.target.value })} className="w-40" /></div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    {[
                        { label: 'Opening cash', value: report.opening_cash },
                        { label: 'Net change', value: report.net_change },
                        { label: 'Closing cash', value: report.closing_cash },
                    ].map((t) => (
                        <div key={t.label} className="rounded-xl border bg-card p-4">
                            <div className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{t.label}</div>
                            <div className={`mt-1 font-mono text-xl font-bold tabular-nums ${t.value < 0 ? 'text-rose-600' : ''}`}>{money(t.value)}</div>
                        </div>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full max-w-3xl text-sm">
                        <tbody className="divide-y divide-transparent">
                            <SectionBlock title="Operating" section={report.operating} />
                            <SectionBlock title="Investing" section={report.investing} />
                            <SectionBlock title="Financing" section={report.financing} />
                            <tr className="border-t-2 [&>td]:px-4 [&>td]:py-2.5 font-bold"><td className="text-right">Net change in cash</td><td className="text-right font-mono tabular-nums">{money(report.net_change)}</td></tr>
                            <tr className="[&>td]:px-4 [&>td]:py-1.5"><td className="text-right text-muted-foreground">Opening cash</td><td className="text-right font-mono tabular-nums">{money(report.opening_cash)}</td></tr>
                            <tr className="border-t [&>td]:px-4 [&>td]:py-2.5 font-bold"><td className="text-right">Closing cash</td><td className="text-right font-mono tabular-nums">{money(report.closing_cash)}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
