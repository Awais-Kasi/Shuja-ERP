import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/format';

type Line = { item: string; warehouse: string; quantity: number; rate: number; description: string | null };

type Adjustment = {
    id: number;
    number: string | null;
    date: string;
    reason: string;
    memo: string | null;
    status: string;
    offset_account: string | null;
    journal_id: number | null;
    lines: Line[];
};

export default function ShowAdjustment({ adjustment }: { adjustment: Adjustment }) {
    return (
        <>
            <Head title={`Adjustment ${adjustment.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/inventory/adjustments"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                                {adjustment.number}
                                <Badge variant="secondary" className="capitalize">{adjustment.status}</Badge>
                            </h1>
                            <p className="text-muted-foreground text-sm capitalize">{adjustment.reason} · {adjustment.date}</p>
                        </div>
                    </div>
                    {adjustment.journal_id && (
                        <Button asChild variant="outline"><Link href={`/accounting/journals/${adjustment.journal_id}`}>View journal</Link></Button>
                    )}
                </div>

                <dl className="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-3">
                    <div><dt className="text-muted-foreground text-xs uppercase">Offset account</dt><dd className="mt-0.5 text-sm">{adjustment.offset_account ?? '—'}</dd></div>
                    <div className="sm:col-span-2"><dt className="text-muted-foreground text-xs uppercase">Memo</dt><dd className="mt-0.5 text-sm">{adjustment.memo ?? '—'}</dd></div>
                </dl>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-24">Warehouse</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Rate</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {adjustment.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.item}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{l.warehouse}</td>
                                    <td className={`px-4 py-2.5 text-right font-mono tabular-nums ${l.quantity < 0 ? 'text-rose-600 dark:text-rose-400' : ''}`}>{money(l.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.rate)}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">{l.description ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
