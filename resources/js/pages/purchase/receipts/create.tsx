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
type PrefillLine = { purchase_order_line_id: number; item_id: number; item_label: string; quantity: number; rate: number };
type Prefill = { purchase_order_id: number; number: string; supplier_id: number; warehouse_id: number | null; lines: PrefillLine[] };
type Line = { item_id: string; purchase_order_line_id: string; quantity: string; rate: string; description: string };

const emptyLine = (): Line => ({ item_id: '', purchase_order_line_id: '', quantity: '', rate: '', description: '' });

export default function CreateReceipt({
    suppliers, warehouses, items, openOrders, prefill, today,
}: {
    suppliers: Option[]; warehouses: Option[]; items: Option[];
    openOrders: { id: number; label: string }[]; prefill: Prefill | null; today: string;
}) {
    const form = useForm<{ supplier_id: string; warehouse_id: string; purchase_order_id: string; receipt_date: string; supplier_dn_no: string; memo: string; lines: Line[] }>({
        supplier_id: prefill ? String(prefill.supplier_id) : '',
        warehouse_id: prefill?.warehouse_id ? String(prefill.warehouse_id) : '',
        purchase_order_id: prefill ? String(prefill.purchase_order_id) : '',
        receipt_date: today,
        supplier_dn_no: '',
        memo: '',
        lines: prefill
            ? prefill.lines.map((l) => ({ item_id: String(l.item_id), purchase_order_line_id: String(l.purchase_order_line_id), quantity: String(l.quantity), rate: String(l.rate), description: '' }))
            : [emptyLine()],
    });

    const lines = form.data.lines;
    const setLines = (n: Line[]) => form.setData('lines', n);
    const update = (i: number, f: keyof Line, v: string) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));
    const total = lines.reduce((s, l) => s + (parseFloat(l.quantity) || 0) * (parseFloat(l.rate) || 0), 0);
    const postingError = (form.errors as Record<string, string>).posting;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            lines: data.lines.map((l) => ({ ...l, purchase_order_line_id: l.purchase_order_line_id || null })),
        })).post('/purchase/receipts');
    };

    return (
        <>
            <Head title="New Goods Receipt" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Goods Receipt</h1>
                    <Button type="submit" disabled={form.processing}>Post receipt</Button>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}
                {prefill && <p className="text-muted-foreground text-sm">Receiving against purchase order <span className="text-foreground font-mono">{prefill.number}</span>.</p>}
                {!prefill && openOrders.length > 0 && (
                    <div className="grid gap-1.5 sm:max-w-md">
                        <Label className="text-xs">Receive against a purchase order</Label>
                        <Select onValueChange={(v) => router.get('/purchase/receipts/create', { purchase_order_id: v })}>
                            <SelectTrigger><SelectValue placeholder="Select a purchase order (optional)" /></SelectTrigger>
                            <SelectContent>{openOrders.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.label}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Supplier</Label>
                        <Select value={form.data.supplier_id} onValueChange={(v) => form.setData('supplier_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                            <SelectContent>{suppliers.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.code} — {s.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.supplier_id && <p className="text-destructive text-xs">{form.errors.supplier_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Warehouse</Label>
                        <Select value={form.data.warehouse_id} onValueChange={(v) => form.setData('warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.warehouse_id && <p className="text-destructive text-xs">{form.errors.warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="d">Receipt date</Label>
                        <Input id="d" type="date" value={form.data.receipt_date} onChange={(e) => form.setData('receipt_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="dn">Supplier DN no.</Label>
                        <Input id="dn" value={form.data.supplier_dn_no} onChange={(e) => form.setData('supplier_dn_no', e.target.value)} placeholder="Optional" />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-[40%]">Item</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Rate</th>
                                <th className="w-32 text-right">Amount</th>
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
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-3 [&>td]:py-2.5">
                                <td colSpan={2}><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button></td>
                                <td className="text-right">Total</td>
                                <td className="text-right font-mono tabular-nums">{money(total)}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">Posting receives stock at these rates and books Dr Inventory / Cr Goods Received Not Invoiced.</p>
            </form>
        </>
    );
}
