import { Head, useForm } from '@inertiajs/react';
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
type Line = { item_id: string; quantity: string; rate: string; description: string };
const emptyLine = (): Line => ({ item_id: '', quantity: '', rate: '', description: '' });

export default function CreateOrder({ suppliers, warehouses, items, today }: { suppliers: Option[]; warehouses: Option[]; items: Option[]; today: string }) {
    const form = useForm<{ supplier_id: string; warehouse_id: string; order_date: string; expected_date: string; memo: string; lines: Line[] }>({
        supplier_id: '', warehouse_id: '', order_date: today, expected_date: '', memo: '', lines: [emptyLine()],
    });
    const lines = form.data.lines;
    const setLines = (n: Line[]) => form.setData('lines', n);
    const update = (i: number, f: keyof Line, v: string) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));
    const total = lines.reduce((s, l) => s + (parseFloat(l.quantity) || 0) * (parseFloat(l.rate) || 0), 0);

    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/purchase/orders'); };

    return (
        <>
            <Head title="New Purchase Order" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Purchase Order</h1>
                    <Button type="submit" disabled={form.processing}>Create order</Button>
                </div>
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
                        <Label>Receiving warehouse</Label>
                        <Select value={form.data.warehouse_id} onValueChange={(v) => form.setData('warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="—" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="d">Order date</Label>
                        <Input id="d" type="date" value={form.data.order_date} onChange={(e) => form.setData('order_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="ed">Expected date</Label>
                        <Input id="ed" type="date" value={form.data.expected_date} onChange={(e) => form.setData('expected_date', e.target.value)} />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-[36%]">Item</th>
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
                                        <td className="text-right font-mono tabular-nums text-muted-foreground pt-3">{money(amt)}</td>
                                        <td className="text-center">
                                            {lines.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-3 [&>td]:py-2.5">
                                <td colSpan={2}>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button>
                                </td>
                                <td className="text-right">Total</td>
                                <td className="text-right font-mono tabular-nums">{money(total)}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </form>
        </>
    );
}
