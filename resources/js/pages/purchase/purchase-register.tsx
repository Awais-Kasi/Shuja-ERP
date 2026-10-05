import { Head, router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';

type Row = { number: string; supplier_invoice_no: string | null; date: string; supplier: string; currency: string; subtotal: number; tax: number; total: number; base_total: number };

export default function PurchaseRegister({ from, to, rows, total, count }: { from: string; to: string; rows: Row[]; total: number; count: number }) {
    const setRange = (k: 'from' | 'to', v: string) =>
        router.get(window.location.pathname, { from: k === 'from' ? v : from, to: k === 'to' ? v : to }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Purchase Register" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Purchase Register</h1>
                        <p className="text-muted-foreground text-sm">{count} posted bill{count === 1 ? '' : 's'} · {from} → {to}</p>
                    </div>
                    <div className="flex gap-3">
                        <div className="grid gap-1.5"><Label className="text-xs">From</Label><Input type="date" value={from} onChange={(e) => setRange('from', e.target.value)} className="w-40" /></div>
                        <div className="grid gap-1.5"><Label className="text-xs">To</Label><Input type="date" value={to} onChange={(e) => setRange('to', e.target.value)} className="w-40" /></div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Bill</th>
                                <th className="text-left">Supplier Inv.</th>
                                <th className="text-left">Date</th>
                                <th className="text-left">Supplier</th>
                                <th className="text-right">Subtotal</th>
                                <th className="text-right">Tax</th>
                                <th className="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.length === 0 && <tr><td colSpan={7} className="text-muted-foreground px-4 py-10 text-center">No bills in this period.</td></tr>}
                            {rows.map((r, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5 hover:bg-muted/40">
                                    <td className="font-mono text-xs">{r.number}</td>
                                    <td className="text-muted-foreground font-mono text-xs">{r.supplier_invoice_no ?? '—'}</td>
                                    <td className="font-mono text-xs">{r.date}</td>
                                    <td>{r.supplier}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.subtotal)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.tax)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.total)} <span className="text-muted-foreground text-xs">{r.currency}</span></td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3">
                                <td colSpan={6}>Total (base currency)</td>
                                <td className="text-right font-mono tabular-nums">{money(total)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
