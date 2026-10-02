import { Head, router } from '@inertiajs/react';
import { Banknote, HandCoins, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; date: string; party: string | null; account: string; amount: number; status: string; allocations: number };
type Paginator<T> = { data: T[]; total: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function PaymentsIndex({ direction, payments }: { direction: string; payments: Paginator<Row> }) {
    const { can } = usePermissions();
    const receipt = direction === 'receive';
    const base = receipt ? '/sales/receipts' : '/purchase/payments';
    const cfg = receipt
        ? { title: 'Customer Receipts', sub: 'Record money received from customers and settle their invoices.', party: 'Customer', from: '#0f766e', to: '#0891b2', Icon: HandCoins, perm: 'sales.receipt.manage', add: 'Record receipt' }
        : { title: 'Supplier Payments', sub: 'Record money paid to suppliers and settle their bills.', party: 'Supplier', from: '#b45309', to: '#c2410c', Icon: Banknote, perm: 'purchase.payment.manage', add: 'Record payment' };

    return (
        <>
            <Head title={cfg.title} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient(cfg.from, cfg.to)}>
                    <cfg.Icon className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-white/70">{receipt ? 'Sales' : 'Purchase'}</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">{cfg.title}</h1>
                            <p className="mt-1 text-sm text-white/80">{cfg.sub}</p>
                        </div>
                        {can(cfg.perm) && <Button className="bg-white text-slate-800 hover:bg-white/90" onClick={() => router.visit(`${base}/create`)}><Plus className="size-4" /> {cfg.add}</Button>}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Number</th>
                                <th className="text-left">Date</th>
                                <th className="text-left">{cfg.party}</th>
                                <th className="text-left">Account</th>
                                <th className="text-right">Amount</th>
                                <th className="text-center">Items</th>
                                <th className="text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {payments.data.length === 0 && <tr><td colSpan={7} className="text-muted-foreground px-4 py-10 text-center">Nothing recorded yet.</td></tr>}
                            {payments.data.map((p) => (
                                <tr key={p.id} onClick={() => router.visit(`${base}/${p.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-mono text-xs">{p.number}</td>
                                    <td className="font-mono text-xs">{p.date}</td>
                                    <td className="font-medium">{p.party ?? '—'}</td>
                                    <td className="text-xs">{p.account}</td>
                                    <td className="text-right font-mono tabular-nums">{money(p.amount)}</td>
                                    <td className="text-center tabular-nums">{p.allocations}</td>
                                    <td><Badge variant="secondary" className={p.status === 'reversed' ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'}><span className="capitalize">{p.status}</span></Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
