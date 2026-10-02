import { Head } from '@inertiajs/react';
import { CheckCircle2, TriangleAlert } from 'lucide-react';
import { money } from '@/lib/format';

type Row = { warehouse: string; item: string; quantity: number; value: number; rate: number };

export default function ConsignmentStock({ rows, stockValue, glValue, reconciled }: { rows: Row[]; stockValue: number; glValue: number; reconciled: boolean }) {
    return (
        <>
            <Head title="Consignment Stock" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Consignment Stock</h1>
                    <p className="text-muted-foreground text-sm">Goods held at consignment / transit locations</p>
                </div>

                <div className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 ${reconciled ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-rose-500/40 bg-rose-500/10'}`}>
                    <div className="flex items-center gap-2 text-sm font-medium">
                        {reconciled ? <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" /> : <TriangleAlert className="size-5 text-rose-600 dark:text-rose-400" />}
                        {reconciled ? 'Stock ledger reconciles to the general ledger' : 'Stock ledger does not match the GL'}
                    </div>
                    <div className="flex gap-6 text-sm">
                        <div><span className="text-muted-foreground">Stock value </span><span className="font-mono font-semibold tabular-nums">{money(stockValue)}</span></div>
                        <div><span className="text-muted-foreground">GL 1124+1140 </span><span className="font-mono font-semibold tabular-nums">{money(glValue)}</span></div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Location</th>
                                <th className="text-left">Item</th>
                                <th className="w-28 text-right">Quantity</th>
                                <th className="w-28 text-right">Unit cost</th>
                                <th className="w-36 text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No stock on consignment.</td></tr>}
                            {rows.map((r, i) => (
                                <tr key={i} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs">{r.warehouse}</td>
                                    <td className="px-4 py-2.5">{r.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(r.value)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3"><td colSpan={4} className="text-right">Total consignment value</td><td className="text-right font-mono tabular-nums">{money(stockValue)}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
