import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; date: string; supplier: string; warehouse: string; status: string; billed: boolean; total_value: number };
type Paginator<T> = { data: T[]; total: number };

export default function ReceiptsIndex({ receipts }: { receipts: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Goods Receipts" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Goods Receipts</h1>
                        <p className="text-muted-foreground text-sm">{receipts.total} receipts</p>
                    </div>
                    {can('purchase.grn.create') && <Button asChild><Link href="/purchase/receipts/create"><Plus className="size-4" /> New receipt</Link></Button>}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th>Supplier</th>
                                <th className="w-20">WH</th>
                                <th className="w-36 text-right">Value</th>
                                <th className="w-24">Billed</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {receipts.data.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No goods receipts yet.</td></tr>}
                            {receipts.data.map((g) => (
                                <tr key={g.id} onClick={() => router.visit(`/purchase/receipts/${g.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{g.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{g.date}</td>
                                    <td className="px-4 py-2.5">{g.supplier}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{g.warehouse}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(g.total_value)}</td>
                                    <td className="px-4 py-2.5">{g.billed ? <Badge variant="secondary" className="bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">billed</Badge> : <Badge variant="outline">unbilled</Badge>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
