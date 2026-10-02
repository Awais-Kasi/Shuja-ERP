import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';

type Row = { id: number; number: string | null; date: string; to: string; agent: string | null; status: string; lines_count: number };
type Paginator<T> = { data: T[]; total: number };

const statusStyle: Record<string, string> = {
    posted: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    partially_settled: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    settled: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    draft: 'bg-muted text-muted-foreground',
};

export default function DispatchesIndex({ dispatches }: { dispatches: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Consignment Dispatches" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Consignment Dispatches</h1>
                        <p className="text-muted-foreground text-sm">{dispatches.total} dispatches</p>
                    </div>
                    {can('consignment.manage') && <Button asChild><Link href="/consignment/dispatches/create"><Plus className="size-4" /> New dispatch</Link></Button>}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th className="w-24">To</th>
                                <th>Agent</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {dispatches.data.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No dispatches yet.</td></tr>}
                            {dispatches.data.map((d) => (
                                <tr key={d.id} onClick={() => router.visit(`/consignment/dispatches/${d.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{d.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{d.date}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{d.to}</td>
                                    <td className="px-4 py-2.5">{d.agent ?? '—'}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className={`capitalize ${statusStyle[d.status] ?? ''}`}>{d.status.replace('_', ' ')}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
