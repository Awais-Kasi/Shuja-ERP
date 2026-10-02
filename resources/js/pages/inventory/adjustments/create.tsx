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

type Item = { id: number; code: string; name: string };
type Warehouse = { id: number; code: string; name: string };
type Account = { id: number; label: string };

type Line = { item_id: string; warehouse_id: string; quantity: string; rate: string; description: string };

const emptyLine = (): Line => ({ item_id: '', warehouse_id: '', quantity: '', rate: '', description: '' });

const REASONS = ['adjustment', 'opening', 'damage', 'count'];

export default function CreateAdjustment({
    items,
    warehouses,
    accounts,
    today,
}: {
    items: Item[];
    warehouses: Warehouse[];
    accounts: Account[];
    today: string;
}) {
    const form = useForm<{
        adjustment_date: string;
        reason: string;
        offset_account_id: string;
        memo: string;
        lines: Line[];
    }>({
        adjustment_date: today,
        reason: 'adjustment',
        offset_account_id: '',
        memo: '',
        lines: [emptyLine()],
    });

    const lines = form.data.lines;
    const setLines = (n: Line[]) => form.setData('lines', n);
    const update = (i: number, field: keyof Line, value: string) =>
        setLines(lines.map((l, idx) => (idx === i ? { ...l, [field]: value } : l)));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/inventory/adjustments');
    };

    const postingError = (form.errors as Record<string, string>).posting;

    return (
        <>
            <Head title="New Stock Adjustment" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Stock Adjustment</h1>
                    <Button type="submit" disabled={form.processing}>Post adjustment</Button>
                </div>

                {postingError && (
                    <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>
                )}

                <div className="grid gap-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="date">Date</Label>
                        <Input id="date" type="date" value={form.data.adjustment_date} onChange={(e) => form.setData('adjustment_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Reason</Label>
                        <Select value={form.data.reason} onValueChange={(v) => form.setData('reason', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{REASONS.map((r) => <SelectItem key={r} value={r} className="capitalize">{r}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Offset account</Label>
                        <Select value={form.data.offset_account_id} onValueChange={(v) => form.setData('offset_account_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select account" /></SelectTrigger>
                            <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.label}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.offset_account_id && <p className="text-destructive text-xs">{form.errors.offset_account_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="memo">Memo</Label>
                        <Input id="memo" value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="Optional" />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[820px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-[28%]">Item</th>
                                <th className="w-40">Warehouse</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Rate</th>
                                <th>Description</th>
                                <th className="w-10"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => (
                                <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-top">
                                    <td>
                                        <Select value={line.item_id} onValueChange={(v) => update(i, 'item_id', v)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="Select item" /></SelectTrigger>
                                            <SelectContent>{items.map((it) => <SelectItem key={it.id} value={String(it.id)}><span className="font-mono text-xs">{it.code}</span> {it.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td>
                                        <Select value={line.warehouse_id} onValueChange={(v) => update(i, 'warehouse_id', v)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="—" /></SelectTrigger>
                                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.quantity} onChange={(e) => update(i, 'quantity', e.target.value)} placeholder="±0" /></td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.rate} onChange={(e) => update(i, 'rate', e.target.value)} placeholder="0.00" /></td>
                                    <td><Input value={line.description} onChange={(e) => update(i, 'description', e.target.value)} placeholder="—" /></td>
                                    <td className="text-center">
                                        {lines.length > 1 && (
                                            <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}>
                                                <Trash2 className="text-muted-foreground size-4" />
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t">
                            <tr><td colSpan={6} className="px-3 py-2.5">
                                <Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}>
                                    <Plus className="size-4" /> Add line
                                </Button>
                            </td></tr>
                        </tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">Positive quantity increases stock (uses the rate); negative decreases it (valued automatically by the item's method).</p>
            </form>
        </>
    );
}
