import { Head, router } from '@inertiajs/react';
import { Plus, Undo2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; date: string; supplier: string; total: number; status: string };
type Paginator<T> = { data: T[]; total: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function PurchaseReturnsIndex({ returns }: { returns: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Purchase Returns" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#b45309', '#92400e')}>
                    <Undo2 className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-amber-100">Purchase</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Purchase Returns</h1>
                            <p className="mt-1 text-sm text-amber-50/80">Debit notes — send goods back to the supplier and reduce what you owe.</p>
                        </div>
                        {can('purchase.return.create') && <Button className="bg-white text-amber-700 hover:bg-white/90" onClick={() => router.visit('/purchase/returns/create')}><Plus className="size-4" /> New debit note</Button>}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Number</th><th className="text-left">Date</th><th className="text-left">Supplier</th><th className="text-right">Total</th><th className="text-left">Status</th></tr></thead>
                        <tbody className="divide-y">
                            {returns.data.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No debit notes yet.</td></tr>}
                            {returns.data.map((r) => (
                                <tr key={r.id} onClick={() => router.visit(`/purchase/returns/${r.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-mono text-xs">{r.number}</td>
                                    <td className="font-mono text-xs">{r.date}</td>
                                    <td className="font-medium">{r.supplier}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.total)}</td>
                                    <td><Badge variant="secondary" className={r.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'}><span className="capitalize">{r.status}</span></Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
