import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type BillLine = { id: number; item_id: number; remaining: number; rate: number };
type Bill = { id: number; supplier_id: number; number: string; warehouse_id: number | null; lines: BillLine[] };
type Line = { item_id: string; purchase_bill_line_id: number | null; quantity: string };

const emptyLine = (): Line => ({ item_id: '', purchase_bill_line_id: null, quantity: '' });

export default function CreatePurchaseReturn({ suppliers, warehouses, items, bills, today }: {
    suppliers: Option[]; warehouses: Option[]; items: Option[]; bills: Bill[]; today: string;
}) {
    const [lines, setLines] = useState<Line[]>([emptyLine()]);
    const form = useForm({ supplier_id: '', purchase_bill_id: '', warehouse_id: '', return_date: today, tax_amount: '', memo: '' });

    const supplierBills = useMemo(() => bills.filter((b) => String(b.supplier_id) === form.data.supplier_id), [bills, form.data.supplier_id]);
    const itemLabel = (id: string) => { const it = items.find((x) => String(x.id) === id); return it ? `${it.code} — ${it.name}` : ''; };

    const setSupplier = (v: string) => { form.setData('supplier_id', v); form.setData('purchase_bill_id', ''); setLines([emptyLine()]); };
    const loadBill = (v: string) => {
        form.setData('purchase_bill_id', v);
        const bill = bills.find((b) => String(b.id) === v);
        if (bill) {
            if (bill.warehouse_id) form.setData('warehouse_id', String(bill.warehouse_id));
            setLines(bill.lines.map((l) => ({ item_id: String(l.item_id), purchase_bill_line_id: l.id, quantity: String(l.remaining) })));
        }
    };
    const update = (i: number, f: keyof Line, val: string) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: val } : l)));

    const tax = parseFloat(form.data.tax_amount) || 0;
    const postingError = (form.errors as Record<string, string>).posting;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            lines: lines.filter((l) => l.item_id && (parseFloat(l.quantity) || 0) > 0).map((l) => ({
                item_id: Number(l.item_id), purchase_bill_line_id: l.purchase_bill_line_id, quantity: parseFloat(l.quantity) || 0,
            })),
        }));
        form.post('/purchase/returns');
    };

    return (
        <>
            <Head title="New Debit Note" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button type="button" variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit('/purchase/returns')}><ArrowLeft className="size-4" /> Back</Button>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">New Debit Note</h1>
                        <Button type="submit" disabled={form.processing || !form.data.supplier_id || !form.data.warehouse_id}>Post debit note</Button>
                    </div>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Supplier</Label>
                        <Select value={form.data.supplier_id} onValueChange={setSupplier}>
                            <SelectTrigger><SelectValue placeholder="Select supplier" /></SelectTrigger>
                            <SelectContent>{suppliers.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.code} — {s.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.supplier_id && <p className="text-destructive text-xs">{form.errors.supplier_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Against bill (optional)</Label>
                        <Select value={form.data.purchase_bill_id} onValueChange={loadBill} disabled={!form.data.supplier_id}>
                            <SelectTrigger><SelectValue placeholder="Free debit note" /></SelectTrigger>
                            <SelectContent>{supplierBills.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.number}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Ship from warehouse</Label>
                        <Select value={form.data.warehouse_id} onValueChange={(v) => form.setData('warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Warehouse" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.warehouse_id && <p className="text-destructive text-xs">{form.errors.warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5"><Label>Date</Label><Input type="date" value={form.data.return_date} onChange={(e) => form.setData('return_date', e.target.value)} /></div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium"><th className="w-[60%]">Item</th><th className="w-40 text-right">Qty</th><th className="w-10"></th></tr></thead>
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
                                    <td className="text-center">{lines.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t"><tr><td colSpan={3} className="px-3 py-2.5"><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button></td></tr></tfoot>
                    </table>
                </div>

                <div className="flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex w-64 items-center justify-between"><span className="text-muted-foreground">Input tax to reverse</span><Input inputMode="decimal" value={form.data.tax_amount} onChange={(e) => form.setData('tax_amount', e.target.value)} className="h-8 w-32 text-right font-mono" placeholder="0.00" /></div>
                </div>
                <p className="text-muted-foreground text-xs">Goods leave the selected warehouse at their carried cost; inventory and input tax are reversed and the supplier's balance is reduced.</p>
            </form>
        </>
    );
}
