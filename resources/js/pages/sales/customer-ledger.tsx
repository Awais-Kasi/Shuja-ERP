import { Head } from '@inertiajs/react';
import { money } from '@/lib/format';

type Row = { id: number; code: string; name: string; receivable: number };

export default function CustomerLedger({ rows, total }: { rows: Row[]; total: number }) {
    return (
        <>
            <Head title="Customer Ledger" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Customer Ledger</h1>
                    <p className="text-muted-foreground text-sm">Accounts receivable by customer</p>
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="w-28 text-left">Code</th>
                                <th className="text-left">Customer</th>
                                <th className="w-48 text-right">Receivable</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.length === 0 && <tr><td colSpan={3} className="text-muted-foreground px-4 py-10 text-center">No outstanding receivables.</td></tr>}
                            {rows.map((r) => (
                                <tr key={r.id} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs">{r.code}</td>
                                    <td className="px-4 py-2.5">{r.name}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.receivable)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3"><td colSpan={2} className="text-right">Total receivable</td><td className="text-right font-mono tabular-nums">{money(total)}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
