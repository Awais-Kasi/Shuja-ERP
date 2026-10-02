import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/format';

type Line = { item: string; quantity: number; rate: number; amount: number };
type Invoice = {
    id: number; number: string | null; date: string; due_date: string | null; customer: string; warehouse: string;
    status: string; subtotal: number; tax_amount: number; total: number; cogs_total: number; journal_id: number | null; lines: Line[];
};

export default function ShowInvoice({ invoice }: { invoice: Invoice }) {
    const grossProfit = invoice.subtotal - invoice.cogs_total;
    return (
        <>
            <Head title={`Invoice ${invoice.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/sales/invoices"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">{invoice.number}<Badge variant="secondary" className="capitalize">{invoice.status}</Badge></h1>
                            <p className="text-muted-foreground text-sm">{invoice.customer} · {invoice.date}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline"><a href={`/sales/invoices/${invoice.id}/print`} target="_blank" rel="noopener"><Printer className="size-4" /> Print / PDF</a></Button>
                        {invoice.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${invoice.journal_id}`}>View journal</Link></Button>}
                    </div>
                </div>
                <dl className="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-4">
                    <div><dt className="text-muted-foreground text-xs uppercase">Warehouse</dt><dd className="mt-0.5 text-sm">{invoice.warehouse}</dd></div>
                    <div><dt className="text-muted-foreground text-xs uppercase">Due date</dt><dd className="mt-0.5 text-sm">{invoice.due_date ?? '—'}</dd></div>
                    <div><dt className="text-muted-foreground text-xs uppercase">COGS</dt><dd className="mt-0.5 font-mono text-sm tabular-nums">{money(invoice.cogs_total)}</dd></div>
                    <div><dt className="text-muted-foreground text-xs uppercase">Gross profit</dt><dd className={`mt-0.5 font-mono text-sm font-semibold tabular-nums ${grossProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>{money(grossProfit)}</dd></div>
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
                            {invoice.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.item}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Subtotal</span><span className="font-mono tabular-nums">{money(invoice.subtotal)}</span></div>
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Output tax</span><span className="font-mono tabular-nums">{money(invoice.tax_amount)}</span></div>
                    <div className="flex w-64 justify-between border-t pt-1.5 text-base font-semibold"><span>Total receivable</span><span className="font-mono tabular-nums">{money(invoice.total)}</span></div>
                </div>
            </div>
        </>
    );
}
