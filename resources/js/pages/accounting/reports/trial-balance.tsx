import { Head, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Row = { id: number; code: string; name: string; type: string; debit: number; credit: number };
type Report = { rows: Row[]; totals: { debit: number; credit: number }; as_of: string };

export default function TrialBalance({ report }: { report: Report }) {
    const balanced = Math.abs(report.totals.debit - report.totals.credit) < 0.005;

    const setAsOf = (value: string) => {
        router.get('/accounting/trial-balance', { as_of: value }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Trial Balance" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Trial Balance</h1>
                        <p className="text-muted-foreground text-sm">Posted balances as of {report.as_of}</p>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="as_of" className="text-xs">As of date</Label>
                        <Input id="as_of" type="date" value={report.as_of} onChange={(e) => setAsOf(e.target.value)} className="w-44" />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="w-28 text-left">Code</th>
                                <th className="text-left">Account</th>
                                <th className="w-32 text-left">Type</th>
                                <th className="w-40 text-right">Debit</th>
                                <th className="w-40 text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {report.rows.length === 0 && (
                                <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No posted transactions yet.</td></tr>
                            )}
                            {report.rows.map((r) => (
                                <tr
                                    key={r.id}
                                    onClick={() => router.visit(`/accounting/general-ledger?account_id=${r.id}`)}
                                    className="hover:bg-muted/40 cursor-pointer"
                                >
                                    <td className="px-4 py-2 font-mono text-xs tabular-nums">{r.code}</td>
                                    <td className="px-4 py-2">{r.name}</td>
                                    <td className="text-muted-foreground px-4 py-2 capitalize">{r.type}</td>
                                    <td className="px-4 py-2 text-right font-mono tabular-nums">{r.debit ? money(r.debit) : ''}</td>
                                    <td className="px-4 py-2 text-right font-mono tabular-nums">{r.credit ? money(r.credit) : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3">
                                <td colSpan={3} className="text-right">
                                    Totals
                                    <span className={`ml-3 rounded px-2 py-0.5 text-xs font-medium ${balanced ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/15 text-rose-600 dark:text-rose-400'}`}>
                                        {balanced ? 'In balance' : 'Out of balance'}
                                    </span>
                                </td>
                                <td className="text-right font-mono tabular-nums">{money(report.totals.debit)}</td>
                                <td className="text-right font-mono tabular-nums">{money(report.totals.credit)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">Click any account to open its ledger.</p>
            </div>
        </>
    );
}
