import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; date: string; item: string; quantity: number; status: string; produced_cost: number };
type Paginator<T> = { data: T[]; total: number };

const statusStyle: Record<string, string> = {
    draft: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    in_progress: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    completed: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    cancelled: 'bg-muted text-muted-foreground',
};

export default function WorkOrdersIndex({ orders }: { orders: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Work Orders" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Work Orders</h1>
                        <p className="text-muted-foreground text-sm">{orders.total} work orders</p>
                    </div>
                    {can('manufacturing.workorder.manage') && <Button asChild><Link href="/manufacturing/work-orders/create"><Plus className="size-4" /> New work order</Link></Button>}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th>Produces</th>
                                <th className="w-24 text-right">Qty</th>
                                <th className="w-36 text-right">Cost</th>
                                <th className="w-28">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {orders.data.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No work orders yet.</td></tr>}
                            {orders.data.map((o) => (
                                <tr key={o.id} onClick={() => router.visit(`/manufacturing/work-orders/${o.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{o.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{o.date}</td>
                                    <td className="px-4 py-2.5">{o.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(o.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{o.produced_cost ? money(o.produced_cost) : '—'}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className={`capitalize ${statusStyle[o.status] ?? ''}`}>{o.status.replace('_', ' ')}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
