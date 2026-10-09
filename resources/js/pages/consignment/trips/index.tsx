import { Head, Link, router } from '@inertiajs/react';
import { Plus, Route } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = {
    id: number; number: string | null; date: string; vehicle_no: string | null; item: string | null;
    route: string | null; quantity: number; total_cost: number; sale_amount: number | null; profit: number | null; status: string;
};
type Paginator<T> = { data: T[]; total: number };

const statusStyle: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    posted: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    settled: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
};

export default function TripsIndex({ trips }: { trips: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Consignment Trips" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight"><Route className="size-6" /> Consignment Trips</h1>
                        <p className="text-muted-foreground text-sm">Landed-cost journeys — every leg's cost rolls into the goods, so each batch shows a true profit or loss. {trips.total} trips.</p>
                    </div>
                    {can('consignment.manage') && <Button asChild><Link href="/consignment/trips/create"><Plus className="size-4" /> New trip</Link></Button>}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-28">Number</th>
                                <th className="w-28">Date</th>
                                <th className="w-28">Truck</th>
                                <th>Item / Route</th>
                                <th className="w-28 text-right">Landed cost</th>
                                <th className="w-28 text-right">Sale</th>
                                <th className="w-28 text-right">Profit/Loss</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {trips.data.length === 0 && <tr><td colSpan={8} className="text-muted-foreground px-4 py-10 text-center">No trips yet. Create one to track a batch from purchase/manufacture through to sale.</td></tr>}
                            {trips.data.map((t) => (
                                <tr key={t.id} onClick={() => router.visit(`/consignment/trips/${t.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{t.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{t.date}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{t.vehicle_no ?? '—'}</td>
                                    <td className="px-4 py-2.5">
                                        <span className="font-mono text-xs">{t.item ?? '—'}</span>
                                        {t.route && <span className="text-muted-foreground"> · {t.route}</span>}
                                    </td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(t.total_cost)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{t.sale_amount !== null ? money(t.sale_amount) : '—'}</td>
                                    <td className={`px-4 py-2.5 text-right font-mono tabular-nums ${t.profit === null ? '' : t.profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>{t.profit !== null ? money(t.profit) : '—'}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className={`capitalize ${statusStyle[t.status] ?? ''}`}>{t.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
