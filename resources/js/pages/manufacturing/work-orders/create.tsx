import { Head, useForm } from '@inertiajs/react';
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

type Bom = { id: number; label: string; output_qty: number };
type Warehouse = { id: number; code: string; name: string };

export default function CreateWorkOrder({ boms, warehouses, today }: { boms: Bom[]; warehouses: Warehouse[]; today: string }) {
    const form = useForm({ bom_id: '', quantity: '', source_warehouse_id: '', target_warehouse_id: '', order_date: today, memo: '' });
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/manufacturing/work-orders'); };

    return (
        <>
            <Head title="New Work Order" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Work Order</h1>
                    <Button type="submit" disabled={form.processing}>Create work order</Button>
                </div>
                <div className="grid max-w-3xl gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label>Bill of material</Label>
                        <Select value={form.data.bom_id} onValueChange={(v) => form.setData('bom_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select BOM" /></SelectTrigger>
                            <SelectContent>{boms.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.label}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.bom_id && <p className="text-destructive text-xs">{form.errors.bom_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="q">Quantity to produce</Label>
                        <Input id="q" inputMode="decimal" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} placeholder="0" />
                        {form.errors.quantity && <p className="text-destructive text-xs">{form.errors.quantity}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Issue materials from</Label>
                        <Select value={form.data.source_warehouse_id} onValueChange={(v) => form.setData('source_warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Source warehouse" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.source_warehouse_id && <p className="text-destructive text-xs">{form.errors.source_warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Receive finished goods into</Label>
                        <Select value={form.data.target_warehouse_id} onValueChange={(v) => form.setData('target_warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Target warehouse" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.target_warehouse_id && <p className="text-destructive text-xs">{form.errors.target_warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="d">Order date</Label>
                        <Input id="d" type="date" value={form.data.order_date} onChange={(e) => form.setData('order_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="memo">Memo</Label>
                        <Input id="memo" value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="Optional" />
                    </div>
                </div>
                <p className="text-muted-foreground text-xs">The BOM's components are scaled to the quantity you produce. Issue materials and complete production from the work order page.</p>
            </form>
        </>
    );
}
