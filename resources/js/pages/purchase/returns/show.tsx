import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { item: string; quantity: number; cost: number };
type R = {
    id: number; number: string | null; date: string; supplier: string; warehouse: string; bill: string | null; status: string;
    subtotal: number; tax_amount: number; total: number; journal_id: number | null; reversal_journal_id: number | null; reversal_journal: string | null; can_reverse: boolean; lines: Line[];
};

export default function PurchaseReturnShow({ purchaseReturn }: { purchaseReturn: R }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const reverse = () => { if (confirm('Reverse this debit note? Goods return to stock and the payable is restored.')) router.post(`/purchase/returns/${purchaseReturn.id}/reverse`, {}, { preserveScroll: true }); };

    return (
        <>
            <Head title={`Debit Note ${purchaseReturn.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="icon" onClick={() => router.visit('/purchase/returns')}><ArrowLeft className="size-4" /></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{purchaseReturn.number}<Badge variant="secondary" className={`capitalize ${purchaseReturn.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'}`}>{purchaseReturn.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{purchaseReturn.supplier} · {purchaseReturn.date}{purchaseReturn.bill ? ` · vs ${purchaseReturn.bill}` : ''}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {purchaseReturn.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${purchaseReturn.journal_id}`}>View journal</Link></Button>}
                        {purchaseReturn.reversal_journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${purchaseReturn.reversal_journal_id}`}><Undo2 className="size-4" /> {purchaseReturn.reversal_journal}</Link></Button>}
                        {purchaseReturn.can_reverse && can('purchase.return.create') && <Button onClick={reverse} variant="outline" className="text-rose-600 hover:text-rose-700"><Undo2 className="size-4" /> Reverse</Button>}
                    </div>
                </div>
                {errors.reversal && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.reversal}</div>}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium"><th>Item</th><th className="text-right">Qty</th><th className="text-right">Cost</th></tr></thead>
                        <tbody className="divide-y">
                            {purchaseReturn.lines.map((l, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5"><td>{l.item}</td><td className="text-right font-mono tabular-nums">{money(l.quantity)}</td><td className="text-right font-mono tabular-nums">{money(l.cost)}</td></tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2">
                            <tr className="[&>td]:px-4 [&>td]:py-1.5"><td colSpan={2} className="text-right text-muted-foreground">Goods subtotal</td><td className="text-right font-mono tabular-nums">{money(purchaseReturn.subtotal)}</td></tr>
                            <tr className="[&>td]:px-4 [&>td]:py-1.5"><td colSpan={2} className="text-right text-muted-foreground">Input tax</td><td className="text-right font-mono tabular-nums">{money(purchaseReturn.tax_amount)}</td></tr>
                            <tr className="[&>td]:px-4 [&>td]:py-2.5 font-semibold"><td colSpan={2} className="text-right">Debit total</td><td className="text-right font-mono tabular-nums">{money(purchaseReturn.total)}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
