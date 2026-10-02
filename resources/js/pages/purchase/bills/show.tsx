import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/format';

type Line = { label: string; quantity: number; rate: number; amount: number };
type Bill = {
    id: number; number: string | null; supplier_invoice_no: string | null; date: string; due_date: string | null;
    supplier: string; status: string; subtotal: number; tax_amount: number; total: number; journal_id: number | null; lines: Line[];
};

export default function ShowBill({ bill }: { bill: Bill }) {
    return (
        <>
            <Head title={`Bill ${bill.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/purchase/bills"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{bill.number}<Badge variant="secondary" className="capitalize">{bill.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{bill.supplier} · {bill.date}</p>
                        </div>
                    </div>
                    {bill.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${bill.journal_id}`}>View journal</Link></Button>}
                </div>
                <dl className="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-4">
                    <div><dt className="text-muted-foreground text-xs uppercase">Invoice no.</dt><dd className="mt-0.5 text-sm">{bill.supplier_invoice_no ?? '—'}</dd></div>
                    <div><dt className="text-muted-foreground text-xs uppercase">Due date</dt><dd className="mt-0.5 text-sm">{bill.due_date ?? '—'}</dd></div>
                </dl>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item / account</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Rate</th>
                                <th className="w-36 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {bill.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.label}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Subtotal</span><span className="font-mono tabular-nums">{money(bill.subtotal)}</span></div>
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Input tax</span><span className="font-mono tabular-nums">{money(bill.tax_amount)}</span></div>
                    <div className="flex w-64 justify-between border-t pt-1.5 text-base font-semibold"><span>Total payable</span><span className="font-mono tabular-nums">{money(bill.total)}</span></div>
                </div>
            </div>
        </>
    );
}
