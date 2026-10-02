import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; date: string; supplier: string; status: string; subtotal: number; lines_count: number };
type Paginator<T> = { data: T[]; total: number };

const statusStyle: Record<string, string> = {
    confirmed: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    received: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    draft: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    cancelled: 'bg-muted text-muted-foreground',
};

export default function OrdersIndex({ orders }: { orders: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Purchase Orders" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Purchase Orders</h1>
                        <p className="text-muted-foreground text-sm">{orders.total} orders</p>
                    </div>
                    {can('purchase.order.manage') && (
                        <Button asChild><Link href="/purchase/orders/create"><Plus className="size-4" /> New order</Link></Button>
                    )}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th>Supplier</th>
                                <th className="w-36 text-right">Amount</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {orders.data.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No purchase orders yet.</td></tr>}
                            {orders.data.map((o) => (
                                <tr key={o.id} onClick={() => router.visit(`/purchase/orders/${o.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{o.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{o.date}</td>
                                    <td className="px-4 py-2.5">{o.supplier}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(o.subtotal)}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className={`capitalize ${statusStyle[o.status] ?? ''}`}>{o.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
