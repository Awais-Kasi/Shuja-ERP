import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';

type Row = {
    id: number;
    number: string | null;
    date: string;
    from: string;
    to: string;
    status: string;
    lines_count: number;
};

type Paginator<T> = { data: T[]; total: number };

export default function TransfersIndex({ transfers }: { transfers: Paginator<Row> }) {
    const { can } = usePermissions();

    return (
        <>
            <Head title="Stock Transfers" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Stock Transfers</h1>
                        <p className="text-muted-foreground text-sm">{transfers.total} transfers</p>
                    </div>
                    {can('inventory.adjustment.create') && (
                        <Button asChild>
                            <Link href="/inventory/transfers/create"><Plus className="size-4" /> New transfer</Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th>Route</th>
                                <th className="w-20 text-right">Lines</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {transfers.data.length === 0 && (
                                <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No transfers yet.</td></tr>
                            )}
                            {transfers.data.map((t) => (
                                <tr key={t.id} onClick={() => router.visit(`/inventory/transfers/${t.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{t.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{t.date}</td>
                                    <td className="px-4 py-2.5">
                                        <span className="inline-flex items-center gap-2 font-mono text-xs">
                                            {t.from} <ArrowRight className="size-3.5" /> {t.to}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{t.lines_count}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className="capitalize">{t.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
