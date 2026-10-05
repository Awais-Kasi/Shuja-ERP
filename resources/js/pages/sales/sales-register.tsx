import { Head, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Row = { number: string; date: string; customer: string; currency: string; subtotal: number; tax: number; total: number; base_total: number };

export default function SalesRegister({ from, to, rows, total, count }: { from: string; to: string; rows: Row[]; total: number; count: number }) {
    const setRange = (k: 'from' | 'to', v: string) =>
        router.get(window.location.pathname, { from: k === 'from' ? v : from, to: k === 'to' ? v : to }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Sales Register" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Sales Register</h1>
                        <p className="text-muted-foreground text-sm">{count} posted invoice{count === 1 ? '' : 's'} · {from} → {to}</p>
                    </div>
                    <div className="flex gap-3">
                        <div className="grid gap-1.5"><Label className="text-xs">From</Label><Input type="date" value={from} onChange={(e) => setRange('from', e.target.value)} className="w-40" /></div>
                        <div className="grid gap-1.5"><Label className="text-xs">To</Label><Input type="date" value={to} onChange={(e) => setRange('to', e.target.value)} className="w-40" /></div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Invoice</th>
                                <th className="text-left">Date</th>
                                <th className="text-left">Customer</th>
                                <th className="text-right">Subtotal</th>
                                <th className="text-right">Tax</th>
                                <th className="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No invoices in this period.</td></tr>}
                            {rows.map((r, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5 hover:bg-muted/40">
                                    <td className="font-mono text-xs">{r.number}</td>
                                    <td className="font-mono text-xs">{r.date}</td>
                                    <td>{r.customer}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.subtotal)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.tax)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.total)} <span className="text-muted-foreground text-xs">{r.currency}</span></td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3">
                                <td colSpan={5}>Total (base currency)</td>
                                <td className="text-right font-mono tabular-nums">{money(total)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
