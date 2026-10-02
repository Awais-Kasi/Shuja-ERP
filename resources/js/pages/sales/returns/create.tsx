import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type InvLine = { id: number; item_id: number; remaining: number; rate: number; unit_cost: number };
type Invoice = { id: number; customer_id: number; number: string; warehouse_id: number; lines: InvLine[] };
type Line = { item_id: string; sales_invoice_line_id: number | null; quantity: string; rate: string };

const emptyLine = (): Line => ({ item_id: '', sales_invoice_line_id: null, quantity: '', rate: '' });

export default function CreateSalesReturn({ customers, warehouses, items, invoices, today }: {
    customers: Option[]; warehouses: Option[]; items: Option[]; invoices: Invoice[]; today: string;
}) {
    const [lines, setLines] = useState<Line[]>([emptyLine()]);
    const form = useForm({ customer_id: '', sales_invoice_id: '', warehouse_id: '', return_date: today, tax_amount: '', memo: '' });

    const customerInvoices = useMemo(() => invoices.filter((i) => String(i.customer_id) === form.data.customer_id), [invoices, form.data.customer_id]);
    const itemLabel = (id: string) => { const it = items.find((x) => String(x.id) === id); return it ? `${it.code} — ${it.name}` : ''; };

    const setCustomer = (v: string) => { form.setData('customer_id', v); form.setData('sales_invoice_id', ''); setLines([emptyLine()]); };
    const loadInvoice = (v: string) => {
        form.setData('sales_invoice_id', v);
        const inv = invoices.find((i) => String(i.id) === v);
        if (inv) {
            form.setData('warehouse_id', String(inv.warehouse_id));
            setLines(inv.lines.map((l) => ({ item_id: String(l.item_id), sales_invoice_line_id: l.id, quantity: String(l.remaining), rate: String(l.rate) })));
        }
    };
    const update = (i: number, f: keyof Line, val: string) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: val } : l)));

    const subtotal = lines.reduce((s, l) => s + (parseFloat(l.quantity) || 0) * (parseFloat(l.rate) || 0), 0);
    const tax = parseFloat(form.data.tax_amount) || 0;
    const postingError = (form.errors as Record<string, string>).posting;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            lines: lines.filter((l) => l.item_id && (parseFloat(l.quantity) || 0) > 0).map((l) => ({
                item_id: Number(l.item_id), sales_invoice_line_id: l.sales_invoice_line_id, quantity: parseFloat(l.quantity) || 0, rate: parseFloat(l.rate) || 0,
            })),
        }));
        form.post('/sales/returns');
    };

    return (
        <>
            <Head title="New Credit Note" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button type="button" variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit('/sales/returns')}><ArrowLeft className="size-4" /> Back</Button>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">New Credit Note</h1>
                        <Button type="submit" disabled={form.processing || !form.data.customer_id || !form.data.warehouse_id}>Post credit note</Button>
                    </div>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Customer</Label>
                        <Select value={form.data.customer_id} onValueChange={setCustomer}>
                            <SelectTrigger><SelectValue placeholder="Select customer" /></SelectTrigger>
                            <SelectContent>{customers.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.code} — {c.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.customer_id && <p className="text-destructive text-xs">{form.errors.customer_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Against invoice (optional)</Label>
                        <Select value={form.data.sales_invoice_id} onValueChange={loadInvoice} disabled={!form.data.customer_id}>
                            <SelectTrigger><SelectValue placeholder="Free credit note" /></SelectTrigger>
                            <SelectContent>{customerInvoices.map((i) => <SelectItem key={i.id} value={String(i.id)}>{i.number}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Return to warehouse</Label>
                        <Select value={form.data.warehouse_id} onValueChange={(v) => form.setData('warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Warehouse" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.warehouse_id && <p className="text-destructive text-xs">{form.errors.warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5"><Label>Date</Label><Input type="date" value={form.data.return_date} onChange={(e) => form.setData('return_date', e.target.value)} /></div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[680px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium"><th className="w-[40%]">Item</th><th className="w-32 text-right">Qty</th><th className="w-32 text-right">Rate</th><th className="w-32 text-right">Amount</th><th className="w-10"></th></tr></thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => (
                                <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-top">
                                    <td>
                                        <Select value={line.item_id} onValueChange={(v) => update(i, 'item_id', v)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="Select item">{itemLabel(line.item_id)}</SelectValue></SelectTrigger>
                                            <SelectContent>{items.map((it) => <SelectItem key={it.id} value={String(it.id)}>{it.code} — {it.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.quantity} onChange={(e) => update(i, 'quantity', e.target.value)} placeholder="0" /></td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.rate} onChange={(e) => update(i, 'rate', e.target.value)} placeholder="0.00" /></td>
                                    <td className="pt-3 text-right font-mono tabular-nums text-muted-foreground">{money((parseFloat(line.quantity) || 0) * (parseFloat(line.rate) || 0))}</td>
                                    <td className="text-center">{lines.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t"><tr><td colSpan={5} className="px-3 py-2.5"><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button></td></tr></tfoot>
                    </table>
                </div>

                <div className="flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Subtotal</span><span className="font-mono tabular-nums">{money(subtotal)}</span></div>
                    <div className="flex w-64 items-center justify-between"><span className="text-muted-foreground">Output tax</span><Input inputMode="decimal" value={form.data.tax_amount} onChange={(e) => form.setData('tax_amount', e.target.value)} className="h-8 w-32 text-right font-mono" placeholder="0.00" /></div>
                    <div className="flex w-64 justify-between border-t pt-1.5 text-base font-semibold"><span>Credit total</span><span className="font-mono tabular-nums">{money(subtotal + tax)}</span></div>
                </div>
                <p className="text-muted-foreground text-xs">Goods return to the selected warehouse at their original cost; revenue, tax and COGS are reversed and the customer's balance is reduced.</p>
            </form>
        </>
    );
}
