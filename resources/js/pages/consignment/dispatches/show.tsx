import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, HandCoins, PackageCheck, PlusCircle, Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { item: string; quantity: number; dispatch_rate: number; dispatch_value: number; settled_qty: number; returned_qty: number; remaining: number };
type Expense = { id: number; number: string | null; date: string; amount: number; status: string; journal_id: number | null; reversal_journal: string | null; reversal_journal_id: number | null; can_reverse: boolean };
type Dispatch = {
    id: number; number: string | null; date: string; from: string; to: string; agent: string | null;
    status: string; memo: string | null; journal_id: number | null; via_transit: boolean;
    reversal_journal_id: number | null; reversal_journal: string | null; can_reverse: boolean; can_receive: boolean; lines: Line[]; expenses: Expense[];
};

export default function ShowDispatch({ dispatch }: { dispatch: Dispatch }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const open = dispatch.status !== 'settled' && dispatch.status !== 'reversed';
    const readyToSettle = open && dispatch.status !== 'in_transit';

    const reverse = () => {
        if (confirm('Reverse this dispatch? The goods return to the source warehouse and a mirror journal is posted.')) {
            router.post(`/consignment/dispatches/${dispatch.id}/reverse`, {}, { preserveScroll: true });
        }
    };
    const receive = () => {
        router.post(`/consignment/dispatches/${dispatch.id}/receive`, {}, { preserveScroll: true });
    };
    const reverseExpense = (id: number) => {
        if (confirm('Reverse this expense? The capitalised landed cost is removed from consignment stock.')) {
            router.post(`/consignment/expenses/${id}/reverse`, {}, { preserveScroll: true });
        }
    };
    return (
        <>
            <Head title={`Dispatch ${dispatch.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/consignment/dispatches"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{dispatch.number}<Badge variant="secondary" className="capitalize">{dispatch.status.replace('_', ' ')}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{dispatch.from} → {dispatch.to}{dispatch.agent ? ` · ${dispatch.agent}` : ''}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {dispatch.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${dispatch.journal_id}`}>View journal</Link></Button>}
                        {dispatch.reversal_journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${dispatch.reversal_journal_id}`}><Undo2 className="size-4" /> {dispatch.reversal_journal}</Link></Button>}
                        {dispatch.can_receive && can('consignment.manage') && <Button onClick={receive}><PackageCheck className="size-4" /> Receive on consignment</Button>}
                        {readyToSettle && can('consignment.expense.manage') && <Button asChild variant="outline"><Link href={`/consignment/expenses/create?dispatch_id=${dispatch.id}`}><PlusCircle className="size-4" /> Add expense</Link></Button>}
                        {readyToSettle && can('consignment.settle') && <Button asChild><Link href={`/consignment/settlements/create?dispatch_id=${dispatch.id}`}><HandCoins className="size-4" /> Settle</Link></Button>}
                        {dispatch.can_reverse && can('consignment.manage') && <Button onClick={reverse} variant="outline" className="text-rose-600 hover:text-rose-700"><Undo2 className="size-4" /> Reverse</Button>}
                    </div>
                </div>
                {errors.reversal && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.reversal}</div>}
                {errors.receive && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.receive}</div>}
                {dispatch.status === 'in_transit' && (
                    <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-400">
                        <PackageCheck className="size-4" /> These goods are in transit (Dr Goods in Transit). Receive them onto the consignment warehouse before settling.
                    </div>
                )}
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-24 text-right">Qty</th>
                                <th className="w-28 text-right">Unit cost</th>
                                <th className="w-24 text-right">Sold</th>
                                <th className="w-24 text-right">Returned</th>
                                <th className="w-24 text-right">Remaining</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {dispatch.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.dispatch_rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.settled_qty)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.returned_qty)}</td>
                                    <td className={`px-4 py-2.5 text-right font-mono tabular-nums ${l.remaining > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}`}>{money(l.remaining)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {dispatch.expenses.length > 0 && (
                    <div>
                        <h2 className="text-muted-foreground mb-2 text-sm font-semibold uppercase tracking-wide">Capitalised Expenses</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                        <th>Number</th>
                                        <th>Date</th>
                                        <th className="text-right">Amount</th>
                                        <th>Status</th>
                                        <th>Journal</th>
                                        <th className="w-24"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {dispatch.expenses.map((e) => (
                                        <tr key={e.id} className="hover:bg-muted/40 [&>td]:px-4 [&>td]:py-2">
                                            <td className="font-mono text-xs">{e.number ?? '—'}</td>
                                            <td className="tabular-nums">{e.date}</td>
                                            <td className="text-right font-mono tabular-nums">{money(e.amount)}</td>
                                            <td><Badge variant="secondary" className={`capitalize ${e.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-sky-500/15 text-sky-600 dark:text-sky-400'}`}>{e.status}</Badge></td>
                                            <td className="text-xs">
                                                {e.journal_id && <Link href={`/accounting/journals/${e.journal_id}`} className="text-indigo-600 hover:underline dark:text-indigo-400">view</Link>}
                                                {e.reversal_journal_id && <> · <Link href={`/accounting/journals/${e.reversal_journal_id}`} className="text-rose-600 hover:underline dark:text-rose-400">{e.reversal_journal}</Link></>}
                                            </td>
                                            <td className="text-right">
                                                {e.can_reverse && can('consignment.expense.manage') && (
                                                    <Button variant="ghost" size="sm" onClick={() => reverseExpense(e.id)} className="text-rose-600 hover:bg-rose-500/10 hover:text-rose-700"><Undo2 className="size-4" /> Reverse</Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
