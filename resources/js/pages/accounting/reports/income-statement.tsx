import { Head, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Line = { code: string; name: string; amount: number };
type Section = { rows: Line[]; total: number };
type Report = {
    from: string; to: string;
    revenue: Section; cost_of_sales: Section; operating_expenses: Section; other_income: Section; other_expenses: Section;
    gross_profit: number; operating_profit: number; net_profit: number;
};

function SectionBlock({ title, section }: { title: string; section: Section }) {
    if (section.rows.length === 0) return null;
    return (
        <>
            <tr className="bg-muted/40"><td colSpan={2} className="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</td></tr>
            {section.rows.map((r) => (
                <tr key={r.code + r.name} className="[&>td]:px-4 [&>td]:py-1.5">
                    <td><span className="text-muted-foreground mr-2 font-mono text-xs">{r.code}</span>{r.name}</td>
                    <td className="text-right font-mono tabular-nums">{money(r.amount)}</td>
                </tr>
            ))}
            <tr className="border-t [&>td]:px-4 [&>td]:py-1.5 font-medium"><td className="text-right text-muted-foreground">{title} total</td><td className="text-right font-mono tabular-nums">{money(section.total)}</td></tr>
        </>
    );
}

function TotalRow({ label, value, strong }: { label: string; value: number; strong?: boolean }) {
    return (
        <tr className={`border-t-2 [&>td]:px-4 [&>td]:py-2.5 ${strong ? 'text-base font-bold' : 'font-semibold'}`}>
            <td className="text-right">{label}</td>
            <td className={`text-right font-mono tabular-nums ${value < 0 ? 'text-rose-600' : ''}`}>{money(value)}</td>
        </tr>
    );
}

export default function IncomeStatement({ report, filters }: { report: Report; filters: { from: string; to: string } }) {
    const setRange = (patch: Partial<{ from: string; to: string }>) =>
        router.get('/accounting/income-statement', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Income Statement" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Income Statement</h1>
                        <p className="text-muted-foreground text-sm">Profit &amp; loss for {report.from} → {report.to}</p>
                    </div>
                    <div className="flex gap-3">
                        <div className="grid gap-1.5"><Label className="text-xs">From</Label><Input type="date" value={filters.from} onChange={(e) => setRange({ from: e.target.value })} className="w-40" /></div>
                        <div className="grid gap-1.5"><Label className="text-xs">To</Label><Input type="date" value={filters.to} onChange={(e) => setRange({ to: e.target.value })} className="w-40" /></div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full max-w-3xl text-sm">
                        <tbody className="divide-y divide-transparent">
                            <SectionBlock title="Revenue" section={report.revenue} />
                            <SectionBlock title="Cost of Sales" section={report.cost_of_sales} />
                            <TotalRow label="Gross Profit" value={report.gross_profit} />
                            <SectionBlock title="Operating Expenses" section={report.operating_expenses} />
                            <TotalRow label="Operating Profit" value={report.operating_profit} />
                            <SectionBlock title="Other Income" section={report.other_income} />
                            <SectionBlock title="Other Expenses" section={report.other_expenses} />
                            <TotalRow label="Net Profit" value={report.net_profit} strong />
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
