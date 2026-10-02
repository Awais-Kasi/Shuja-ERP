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

type PrefillLine = { item_id: number; item_label: string; quantity: number; rate: number };
type Prefill = { goods_receipt_id: number; supplier_id: number; lines: PrefillLine[] };
type Line = { item_id: string; item_label: string; quantity: string; rate: string };

export default function CreateBill({
    suppliers, unbilledReceipts, prefill, today,
}: {
    suppliers: { id: number; code: string; name: string }[];
    unbilledReceipts: { id: number; label: string }[];
    prefill: Prefill | null;
    today: string;
}) {
    const form = useForm<{ supplier_id: string; goods_receipt_id: string; bill_date: string; due_date: string; supplier_invoice_no: string; tax_amount: string; memo: string; lines: Line[] }>({
        supplier_id: prefill ? String(prefill.supplier_id) : '',
        goods_receipt_id: prefill ? String(prefill.goods_receipt_id) : '',
        bill_date: today,
        due_date: '',
        supplier_invoice_no: '',
        tax_amount: '',
        memo: '',
        lines: prefill ? prefill.lines.map((l) => ({ item_id: String(l.item_id), item_label: l.item_label, quantity: String(l.quantity), rate: String(l.rate) })) : [],
    });

    const lines = form.data.lines;
    const update = (i: number, f: keyof Line, v: string) => form.setData('lines', lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));
    const subtotal = lines.reduce((s, l) => s + (parseFloat(l.quantity) || 0) * (parseFloat(l.rate) || 0), 0);
    const tax = parseFloat(form.data.tax_amount) || 0;
    const postingError = (form.errors as Record<string, string>).posting;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, lines: data.lines.map((l) => ({ item_id: l.item_id, quantity: l.quantity, rate: l.rate })) }));
        form.post('/purchase/bills');
    };

    if (!prefill) {
        return (
            <>
                <Head title="New Purchase Bill" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <h1 className="text-2xl font-semibold tracking-tight">New Purchase Bill</h1>
                    <div className="grid max-w-md gap-1.5">
                        <Label>Bill an unbilled goods receipt</Label>
                        <Select onValueChange={(v) => router.get('/purchase/bills/create', { goods_receipt_id: v })}>
                            <SelectTrigger><SelectValue placeholder="Select a goods receipt" /></SelectTrigger>
                            <SelectContent>
                                {unbilledReceipts.length === 0 && <div className="text-muted-foreground px-2 py-4 text-center text-sm">No unbilled receipts.</div>}
                                {unbilledReceipts.map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.label}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <p className="text-muted-foreground mt-1 text-xs">A bill clears the goods-received accrual and raises Accounts Payable for the supplier.</p>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="New Purchase Bill" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Purchase Bill</h1>
                    <Button type="submit" disabled={form.processing}>Post bill</Button>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}

                <div className="grid gap-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Supplier</Label>
                        <Select value={form.data.supplier_id} onValueChange={(v) => form.setData('supplier_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                            <SelectContent>{suppliers.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.code} — {s.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="inv">Supplier invoice no.</Label>
                        <Input id="inv" value={form.data.supplier_invoice_no} onChange={(e) => form.setData('supplier_invoice_no', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="bd">Bill date</Label>
                        <Input id="bd" type="date" value={form.data.bill_date} onChange={(e) => form.setData('bill_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="dd">Due date</Label>
                        <Input id="dd" type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Rate</th>
                                <th className="w-36 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => (
                                <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-top">
                                    <td className="pt-3">{line.item_label}</td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.quantity} onChange={(e) => update(i, 'quantity', e.target.value)} /></td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.rate} onChange={(e) => update(i, 'rate', e.target.value)} /></td>
                                    <td className="text-muted-foreground pt-3 text-right font-mono tabular-nums">{money((parseFloat(line.quantity) || 0) * (parseFloat(line.rate) || 0))}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-col items-end gap-2">
                    <div className="flex w-72 items-center justify-between text-sm"><span className="text-muted-foreground">Subtotal</span><span className="font-mono tabular-nums">{money(subtotal)}</span></div>
                    <div className="flex w-72 items-center justify-between gap-3 text-sm">
                        <span className="text-muted-foreground">Input tax</span>
                        <Input inputMode="decimal" className="h-8 w-32 text-right font-mono tabular-nums" value={form.data.tax_amount} onChange={(e) => form.setData('tax_amount', e.target.value)} placeholder="0.00" />
                    </div>
                    <div className="flex w-72 items-center justify-between border-t pt-2 text-base font-semibold"><span>Total</span><span className="font-mono tabular-nums">{money(subtotal + tax)}</span></div>
                </div>
            </form>
        </>
    );
}
