import { Head, router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Line = { code: string; name: string; amount: number };
type Section = { rows: Line[]; total: number };
type Report = {
    as_of: string;
    assets: Section; liabilities: Section; equity: Section;
    liabilities_equity_total: number; balanced: boolean;
};

function Statement({ title, section }: { title: string; section: Section }) {
    return (
        <div className="overflow-hidden rounded-xl border">
            <div className="bg-muted/50 px-4 py-2.5 text-sm font-semibold">{title}</div>
            <table className="w-full text-sm">
                <tbody className="divide-y">
                    {section.rows.length === 0 && <tr><td className="text-muted-foreground px-4 py-6 text-center">None</td></tr>}
                    {section.rows.map((r) => (
                        <tr key={r.code + r.name} className="[&>td]:px-4 [&>td]:py-2">
                            <td><span className="text-muted-foreground mr-2 font-mono text-xs">{r.code}</span>{r.name}</td>
                            <td className="text-right font-mono tabular-nums">{money(r.amount)}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot className="border-t-2 font-semibold"><tr className="[&>td]:px-4 [&>td]:py-2.5"><td>Total {title}</td><td className="text-right font-mono tabular-nums">{money(section.total)}</td></tr></tfoot>
            </table>
        </div>
    );
}

export default function BalanceSheet({ report }: { report: Report }) {
    const setAsOf = (v: string) => router.get('/accounting/balance-sheet', { as_of: v }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Balance Sheet" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Balance Sheet</h1>
                        <p className="text-muted-foreground text-sm">Financial position as of {report.as_of}</p>
                    </div>
                    <div className="grid gap-1.5"><Label className="text-xs">As of date</Label><Input type="date" value={report.as_of} onChange={(e) => setAsOf(e.target.value)} className="w-44" /></div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Statement title="Assets" section={report.assets} />
                    <div className="flex flex-col gap-6">
                        <Statement title="Liabilities" section={report.liabilities} />
                        <Statement title="Equity" section={report.equity} />
                    </div>
                </div>

                <div className={`flex items-center justify-between rounded-xl border px-5 py-4 text-sm font-semibold ${report.balanced ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-400' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                    <span className="flex items-center gap-2">{report.balanced && <CheckCircle2 className="size-4" />} Assets {money(report.assets.total)} = Liabilities + Equity {money(report.liabilities_equity_total)}</span>
                    <span>{report.balanced ? 'Balanced' : 'Out of balance'}</span>
                </div>
            </div>
        </>
    );
}
