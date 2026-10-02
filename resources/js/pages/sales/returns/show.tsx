import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { item: string; quantity: number; rate: number; amount: number };
type R = {
    id: number; number: string | null; date: string; customer: string; warehouse: string; invoice: string | null; status: string;
    subtotal: number; tax_amount: number; total: number; journal_id: number | null; reversal_journal_id: number | null; reversal_journal: string | null; can_reverse: boolean; lines: Line[];
};

export default function SalesReturnShow({ salesReturn }: { salesReturn: R }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const reverse = () => { if (confirm('Reverse this credit note? Goods leave stock again and the customer balance is restored.')) router.post(`/sales/returns/${salesReturn.id}/reverse`, {}, { preserveScroll: true }); };

    return (
        <>
            <Head title={`Credit Note ${salesReturn.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="icon" onClick={() => router.visit('/sales/returns')}><ArrowLeft className="size-4" /></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{salesReturn.number}<Badge variant="secondary" className={`capitalize ${salesReturn.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'}`}>{salesReturn.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{salesReturn.customer} · {salesReturn.date}{salesReturn.invoice ? ` · vs ${salesReturn.invoice}` : ''}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {salesReturn.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${salesReturn.journal_id}`}>View journal</Link></Button>}
                        {salesReturn.reversal_journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${salesReturn.reversal_journal_id}`}><Undo2 className="size-4" /> {salesReturn.reversal_journal}</Link></Button>}
                        {salesReturn.can_reverse && can('sales.return.create') && <Button onClick={reverse} variant="outline" className="text-rose-600 hover:text-rose-700"><Undo2 className="size-4" /> Reverse</Button>}
                    </div>
                </div>
                {errors.reversal && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.reversal}</div>}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium"><th>Item</th><th className="text-right">Qty</th><th className="text-right">Rate</th><th className="text-right">Amount</th></tr></thead>
                        <tbody className="divide-y">
                            {salesReturn.lines.map((l, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5"><td>{l.item}</td><td className="text-right font-mono tabular-nums">{money(l.quantity)}</td><td className="text-right font-mono tabular-nums">{money(l.rate)}</td><td className="text-right font-mono tabular-nums">{money(l.amount)}</td></tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2">
                            <tr className="[&>td]:px-4 [&>td]:py-1.5"><td colSpan={3} className="text-right text-muted-foreground">Subtotal</td><td className="text-right font-mono tabular-nums">{money(salesReturn.subtotal)}</td></tr>
                            <tr className="[&>td]:px-4 [&>td]:py-1.5"><td colSpan={3} className="text-right text-muted-foreground">Output tax</td><td className="text-right font-mono tabular-nums">{money(salesReturn.tax_amount)}</td></tr>
                            <tr className="[&>td]:px-4 [&>td]:py-2.5 font-semibold"><td colSpan={3} className="text-right">Credit total</td><td className="text-right font-mono tabular-nums">{money(salesReturn.total)}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
