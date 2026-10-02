import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, BookOpenText, Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { item: string; sold_qty: number; rate: number; amount: number; returned_qty: number };
type Settlement = {
    id: number; number: string | null; date: string; dispatch: string | null; agent: string | null; status: string;
    subtotal: number; tax_amount: number; commission_amount: number; total: number; cogs_total: number;
    journal_id: number | null; reversal_journal_id: number | null; reversal_journal: string | null; can_reverse: boolean; lines: Line[];
};

const statusStyle: Record<string, string> = {
    posted: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    reversed: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
};

export default function ShowSettlement({ settlement }: { settlement: Settlement }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const grossProfit = settlement.subtotal - settlement.cogs_total;

    const reverse = () => {
        if (confirm('Reverse this settlement? The sale is un-recognized and the stock returns to consignment.')) {
            router.post(`/consignment/settlements/${settlement.id}/reverse`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Settlement ${settlement.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/consignment/settlements"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{settlement.number}<Badge variant="secondary" className={`capitalize ${statusStyle[settlement.status] ?? ''}`}>{settlement.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{settlement.agent ?? '—'} · dispatch {settlement.dispatch ?? '—'} · {settlement.date}{settlement.commission_amount > 0 ? ` · commission ${money(settlement.commission_amount)}` : ''}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {settlement.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${settlement.journal_id}`}><BookOpenText className="size-4" /> View journal</Link></Button>}
                        {settlement.reversal_journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${settlement.reversal_journal_id}`}><Undo2 className="size-4" /> {settlement.reversal_journal}</Link></Button>}
                        {settlement.can_reverse && can('consignment.settle') && <Button onClick={reverse} variant="outline" className="text-rose-600 hover:text-rose-700"><Undo2 className="size-4" /> Reverse</Button>}
                    </div>
                </div>
                {errors.reversal && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.reversal}</div>}
                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Sales" value={settlement.subtotal} />
                    <Stat label="COGS" value={settlement.cogs_total} />
                    <Stat label="Gross profit" value={grossProfit} highlight />
                    <Stat label="Receivable" value={settlement.total} />
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-28 text-right">Sold</th>
                                <th className="w-28 text-right">Rate</th>
                                <th className="w-32 text-right">Amount</th>
                                <th className="w-28 text-right">Returned</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {settlement.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.sold_qty)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.amount)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.returned_qty)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function Stat({ label, value, highlight }: { label: string; value: number; highlight?: boolean }) {
    return (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-xs uppercase tracking-wide">{label}</div>
            <div className={`mt-1 font-mono text-xl font-semibold tabular-nums ${highlight ? (value >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400') : ''}`}>{money(value)}</div>
        </div>
    );
}
