import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type PrefillLine = { sales_order_line_id: number; item_id: number; item_label: string; quantity: number; rate: number };
type Prefill = { sales_order_id: number; number: string; customer_id: number; warehouse_id: number | null; lines: PrefillLine[] };
type Line = { item_id: string; sales_order_line_id: string; quantity: string; rate: string; description: string };

const emptyLine = (): Line => ({ item_id: '', sales_order_line_id: '', quantity: '', rate: '', description: '' });

export default function CreateInvoice({
    customers, warehouses, items, openOrders, prefill, today, baseCurrency, currencies, rates,
}: {
    customers: Option[]; warehouses: Option[]; items: Option[];
    openOrders: { id: number; label: string }[]; prefill: Prefill | null; today: string;
    baseCurrency: string; currencies: string[]; rates: Record<string, number>;
}) {
    const form = useForm<{ customer_id: string; warehouse_id: string; sales_order_id: string; invoice_date: string; due_date: string; currency: string; fx_rate: string; tax_amount: string; memo: string; lines: Line[] }>({
        customer_id: prefill ? String(prefill.customer_id) : '',
        warehouse_id: prefill?.warehouse_id ? String(prefill.warehouse_id) : '',
        sales_order_id: prefill ? String(prefill.sales_order_id) : '',
        invoice_date: today,
        due_date: '',
        currency: baseCurrency,
        fx_rate: '1',
        tax_amount: '',
        memo: '',
        lines: prefill
            ? prefill.lines.map((l) => ({ item_id: String(l.item_id), sales_order_line_id: String(l.sales_order_line_id), quantity: String(l.quantity), rate: String(l.rate), description: '' }))
            : [emptyLine()],
    });

    const lines = form.data.lines;
    const setLines = (n: Line[]) => form.setData('lines', n);
    const update = (i: number, f: keyof Line, v: string) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));
    const subtotal = lines.reduce((s, l) => s + (parseFloat(l.quantity) || 0) * (parseFloat(l.rate) || 0), 0);
    const tax = parseFloat(form.data.tax_amount) || 0;
    const postingError = (form.errors as Record<string, string>).posting;

    const isForeign = form.data.currency !== baseCurrency;
    const fxRate = parseFloat(form.data.fx_rate) || 0;
    const pickCurrency = (c: string) => {
        form.setData('currency', c);
        form.setData('fx_rate', c === baseCurrency ? '1' : String(rates[c] ?? ''));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, lines: data.lines.map((l) => ({ ...l, sales_order_line_id: l.sales_order_line_id || null })) }));
        form.post('/sales/invoices');
    };

    return (
        <>
            <Head title="New Sales Invoice" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Sales Invoice</h1>
                    <Button type="submit" disabled={form.processing}>Post invoice</Button>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}
                {prefill && <p className="text-muted-foreground text-sm">Invoicing sales order <span className="text-foreground font-mono">{prefill.number}</span>.</p>}
                {!prefill && openOrders.length > 0 && (
                    <div className="grid gap-1.5 sm:max-w-md">
                        <Label className="text-xs">Invoice a sales order</Label>
                        <Select onValueChange={(v) => router.get('/sales/invoices/create', { sales_order_id: v })}>
                            <SelectTrigger><SelectValue placeholder="Select a sales order (optional)" /></SelectTrigger>
                            <SelectContent>{openOrders.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.label}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Customer</Label>
                        <Select value={form.data.customer_id} onValueChange={(v) => form.setData('customer_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                            <SelectContent>{customers.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.code} — {s.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.customer_id && <p className="text-destructive text-xs">{form.errors.customer_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Ship from warehouse</Label>
                        <Select value={form.data.warehouse_id} onValueChange={(v) => form.setData('warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.warehouse_id && <p className="text-destructive text-xs">{form.errors.warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="d">Invoice date</Label>
                        <Input id="d" type="date" value={form.data.invoice_date} onChange={(e) => form.setData('invoice_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="dd">Due date</Label>
                        <Input id="dd" type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Currency</Label>
                        <Select value={form.data.currency} onValueChange={pickCurrency}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{currencies.map((c) => <SelectItem key={c} value={c}>{c}{c === baseCurrency ? ' · base' : ''}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    {isForeign && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="fx">Rate → {baseCurrency}</Label>
                            <Input id="fx" inputMode="decimal" className="text-right font-mono tabular-nums" value={form.data.fx_rate} onChange={(e) => form.setData('fx_rate', e.target.value)} placeholder="0.0000" />
                            {form.errors.fx_rate && <p className="text-destructive text-xs">{form.errors.fx_rate}</p>}
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-[40%]">Item</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Rate</th>
                                <th className="w-36 text-right">Amount</th>
                                <th className="w-10"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => {
                                const amt = (parseFloat(line.quantity) || 0) * (parseFloat(line.rate) || 0);
                                return (
                                    <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-top">
                                        <td>
                                            <Select value={line.item_id} onValueChange={(v) => update(i, 'item_id', v)}>
                                                <SelectTrigger className="w-full"><SelectValue placeholder="Select item" /></SelectTrigger>
                                                <SelectContent>{items.map((it) => <SelectItem key={it.id} value={String(it.id)}><span className="font-mono text-xs">{it.code}</span> {it.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </td>
                                        <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.quantity} onChange={(e) => update(i, 'quantity', e.target.value)} placeholder="0" /></td>
                                        <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.rate} onChange={(e) => update(i, 'rate', e.target.value)} placeholder="0.00" /></td>
                                        <td className="text-muted-foreground pt-3 text-right font-mono tabular-nums">{money(amt)}</td>
                                        <td className="text-center">{lines.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot className="border-t">
                            <tr><td colSpan={5} className="px-3 py-2.5"><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button></td></tr>
                        </tfoot>
                    </table>
                </div>

                <div className="flex flex-col items-end gap-2">
                    <div className="flex w-72 items-center justify-between text-sm"><span className="text-muted-foreground">Subtotal</span><span className="font-mono tabular-nums">{money(subtotal)} {form.data.currency}</span></div>
                    <div className="flex w-72 items-center justify-between gap-3 text-sm">
                        <span className="text-muted-foreground">Output tax</span>
                        <Input inputMode="decimal" className="h-8 w-32 text-right font-mono tabular-nums" value={form.data.tax_amount} onChange={(e) => form.setData('tax_amount', e.target.value)} placeholder="0.00" />
                    </div>
                    <div className="flex w-72 items-center justify-between border-t pt-2 text-base font-semibold"><span>Total</span><span className="font-mono tabular-nums">{money(subtotal + tax)} {form.data.currency}</span></div>
                    {isForeign && fxRate > 0 && (
                        <div className="flex w-72 items-center justify-between text-xs text-muted-foreground"><span>≈ in {baseCurrency}</span><span className="font-mono tabular-nums">{money((subtotal + tax) * fxRate)}</span></div>
                    )}
                </div>
                <p className="text-muted-foreground text-xs">Posting ships stock at valued cost (COGS), and books revenue, output tax and receivable{isForeign ? ` in ${form.data.currency} at ${form.data.fx_rate} per unit` : ''}.</p>
            </form>
        </>
    );
}
