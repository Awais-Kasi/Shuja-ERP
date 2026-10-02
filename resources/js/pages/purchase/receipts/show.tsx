import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ReceiptText } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { item: string; quantity: number; rate: number; amount: number };
type Receipt = {
    id: number; number: string | null; date: string; supplier: string; warehouse: string;
    status: string; billed: boolean; memo: string | null; journal_id: number | null; total_value: number; lines: Line[];
};

export default function ShowReceipt({ receipt }: { receipt: Receipt }) {
    const { can } = usePermissions();
    return (
        <>
            <Head title={`GRN ${receipt.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/purchase/receipts"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{receipt.number}<Badge variant="secondary" className="capitalize">{receipt.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{receipt.supplier} · {receipt.date}</p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        {receipt.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${receipt.journal_id}`}>View journal</Link></Button>}
                        {!receipt.billed && can('purchase.bill.post') && (
                            <Button asChild><Link href={`/purchase/bills/create?goods_receipt_id=${receipt.id}`}><ReceiptText className="size-4" /> Create bill</Link></Button>
                        )}
                    </div>
                </div>
                <dl className="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-4">
                    <div><dt className="text-muted-foreground text-xs uppercase">Warehouse</dt><dd className="mt-0.5 text-sm">{receipt.warehouse}</dd></div>
                    <div><dt className="text-muted-foreground text-xs uppercase">Billed</dt><dd className="mt-0.5 text-sm">{receipt.billed ? 'Yes' : 'No'}</dd></div>
                    <div className="sm:col-span-2"><dt className="text-muted-foreground text-xs uppercase">Memo</dt><dd className="mt-0.5 text-sm">{receipt.memo ?? '—'}</dd></div>
                </dl>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Rate</th>
                                <th className="w-36 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {receipt.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-2.5"><td colSpan={3} className="text-right">Total received value</td><td className="text-right font-mono tabular-nums">{money(receipt.total_value)}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
