import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';

type Row = {
    id: number;
    number: string | null;
    date: string;
    reason: string;
    memo: string | null;
    status: string;
    lines_count: number;
};

type Paginator<T> = { data: T[]; total: number };

export default function AdjustmentsIndex({ adjustments }: { adjustments: Paginator<Row> }) {
    const { can } = usePermissions();

    return (
        <>
            <Head title="Stock Adjustments" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Stock Adjustments</h1>
                        <p className="text-muted-foreground text-sm">{adjustments.total} adjustments</p>
                    </div>
                    {can('inventory.adjustment.create') && (
                        <Button asChild>
                            <Link href="/inventory/adjustments/create"><Plus className="size-4" /> New adjustment</Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th className="w-28">Reason</th>
                                <th>Memo</th>
                                <th className="w-20 text-right">Lines</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {adjustments.data.length === 0 && (
                                <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No adjustments yet.</td></tr>
                            )}
                            {adjustments.data.map((a) => (
                                <tr key={a.id} onClick={() => router.visit(`/inventory/adjustments/${a.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{a.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{a.date}</td>
                                    <td className="px-4 py-2.5 capitalize">{a.reason}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">{a.memo ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{a.lines_count}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className="capitalize">{a.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
