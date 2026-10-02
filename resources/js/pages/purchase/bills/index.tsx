import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; supplier_invoice_no: string | null; date: string; supplier: string; status: string; total: number };
type Paginator<T> = { data: T[]; total: number };

export default function BillsIndex({ bills }: { bills: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Purchase Bills" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Purchase Bills</h1>
                        <p className="text-muted-foreground text-sm">{bills.total} bills</p>
                    </div>
                    {can('purchase.bill.post') && <Button asChild><Link href="/purchase/bills/create"><Plus className="size-4" /> New bill</Link></Button>}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-32">Invoice no.</th>
                                <th className="w-28">Date</th>
                                <th>Supplier</th>
                                <th className="w-36 text-right">Total</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {bills.data.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No bills yet.</td></tr>}
                            {bills.data.map((b) => (
                                <tr key={b.id} onClick={() => router.visit(`/purchase/bills/${b.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{b.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{b.supplier_invoice_no ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{b.date}</td>
                                    <td className="px-4 py-2.5">{b.supplier}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(b.total)}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className="capitalize">{b.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
