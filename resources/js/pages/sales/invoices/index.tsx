import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; date: string; customer: string; status: string; total: number };
type Paginator<T> = { data: T[]; total: number };

export default function InvoicesIndex({ invoices }: { invoices: Paginator<Row> }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title="Sales Invoices" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Sales Invoices</h1>
                        <p className="text-muted-foreground text-sm">{invoices.total} invoices</p>
                    </div>
                    {can('sales.invoice.post') && <Button asChild><Link href="/sales/invoices/create"><Plus className="size-4" /> New invoice</Link></Button>}
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th>Customer</th>
                                <th className="w-36 text-right">Total</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {invoices.data.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No invoices yet.</td></tr>}
                            {invoices.data.map((b) => (
                                <tr key={b.id} onClick={() => router.visit(`/sales/invoices/${b.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{b.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{b.date}</td>
                                    <td className="px-4 py-2.5">{b.customer}</td>
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
