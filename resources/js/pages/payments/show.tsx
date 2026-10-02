import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Alloc = { document: string | null; amount: number };
type Payment = {
    id: number; number: string | null; date: string; party: string | null; account: string; amount: number; unapplied: number;
    reference: string | null; memo: string | null; status: string; journal_id: number | null;
    reversal_journal_id: number | null; reversal_journal: string | null; can_reverse: boolean; allocations: Alloc[];
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function PaymentShow({ direction, payment }: { direction: string; payment: Payment }) {
    const { can } = usePermissions();
    const receipt = direction === 'receive';
    const base = receipt ? '/sales/receipts' : '/purchase/payments';
    const perm = receipt ? 'sales.receipt.manage' : 'purchase.payment.manage';
    const { errors } = usePage().props as { errors: Record<string, string> };

    const reverse = () => {
        if (confirm('Reverse this payment? A mirror journal is posted and the settled documents are reopened.')) {
            router.post(`${base}/${payment.id}/reverse`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`${receipt ? 'Receipt' : 'Payment'} ${payment.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="icon" onClick={() => router.visit(base)}><ArrowLeft className="size-4" /></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{payment.number}<Badge variant="secondary" className={`capitalize ${payment.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'}`}>{payment.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{payment.party} · {payment.date}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {payment.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${payment.journal_id}`}>View journal</Link></Button>}
                        {payment.reversal_journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${payment.reversal_journal_id}`}><Undo2 className="size-4" /> {payment.reversal_journal}</Link></Button>}
                        {payment.can_reverse && can(perm) && <Button onClick={reverse} variant="outline" className="text-rose-600 hover:text-rose-700"><Undo2 className="size-4" /> Reverse</Button>}
                    </div>
                </div>
                {errors.reversal && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.reversal}</div>}

                <div className="grid gap-4 sm:grid-cols-4">
                    {[
                        { label: 'Amount', value: money(payment.amount) },
                        { label: 'Applied', value: money(payment.amount - payment.unapplied) },
                        { label: 'On account', value: money(payment.unapplied) },
                        { label: 'Account', value: payment.account },
                    ].map((t) => (
                        <div key={t.label} className="rounded-xl border bg-card p-4">
                            <div className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{t.label}</div>
                            <div className="mt-1 font-mono text-lg font-semibold tabular-nums">{t.value}</div>
                        </div>
                    ))}
                </div>

                {payment.reference && <p className="text-sm text-muted-foreground">Reference: <span className="font-medium text-foreground">{payment.reference}</span></p>}

                <div>
                    <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Allocations</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[420px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Document</th><th className="text-right">Amount</th></tr></thead>
                            <tbody className="divide-y">
                                {payment.allocations.length === 0 && <tr><td colSpan={2} className="text-muted-foreground px-4 py-8 text-center">Unapplied — held on account.</td></tr>}
                                {payment.allocations.map((a, i) => (
                                    <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5"><td className="font-mono text-xs">{a.document ?? '—'}</td><td className="text-right font-mono tabular-nums">{money(a.amount)}</td></tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}
