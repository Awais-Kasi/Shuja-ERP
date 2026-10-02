import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, PackageCheck, Printer } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { item: string; quantity: number; rate: number; amount: number; received_qty: number };
type Order = {
    id: number; number: string | null; date: string; expected_date: string | null;
    supplier: string; warehouse: string | null; status: string; memo: string | null; subtotal: number; lines: Line[];
};

export default function ShowOrder({ order }: { order: Order }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title={`PO ${order.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/purchase/orders"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{order.number}<Badge variant="secondary" className="capitalize">{order.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{order.supplier} · {order.date}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline"><a href={`/purchase/orders/${order.id}/print`} target="_blank" rel="noopener"><Printer className="size-4" /> Print / PDF</a></Button>
                        {order.status !== 'received' && can('purchase.grn.create') && (
                            <Button asChild><Link href={`/purchase/receipts/create?purchase_order_id=${order.id}`}><PackageCheck className="size-4" /> Receive goods</Link></Button>
                        )}
                    </div>
                </div>
                <dl className="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-4">
                    <div><dt className="text-muted-foreground text-xs uppercase">Warehouse</dt><dd className="mt-0.5 text-sm">{order.warehouse ?? '—'}</dd></div>
                    <div><dt className="text-muted-foreground text-xs uppercase">Expected</dt><dd className="mt-0.5 text-sm">{order.expected_date ?? '—'}</dd></div>
                    <div className="sm:col-span-2"><dt className="text-muted-foreground text-xs uppercase">Memo</dt><dd className="mt-0.5 text-sm">{order.memo ?? '—'}</dd></div>
                </dl>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-28 text-right">Ordered</th>
                                <th className="w-28 text-right">Received</th>
                                <th className="w-28 text-right">Rate</th>
                                <th className="w-32 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {order.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className={`px-4 py-2.5 text-right font-mono tabular-nums ${l.received_qty >= l.quantity ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'}`}>{money(l.received_qty)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-2.5"><td colSpan={4} className="text-right">Total</td><td className="text-right font-mono tabular-nums">{money(order.subtotal)}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
