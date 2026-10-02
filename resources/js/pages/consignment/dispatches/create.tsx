import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Option = { id: number; code: string; name: string };
type Line = { item_id: string; quantity: string; description: string };
const emptyLine = (): Line => ({ item_id: '', quantity: '', description: '' });

export default function CreateDispatch({
    sources, consignmentWarehouses, agents, items, today,
}: {
    sources: Option[]; consignmentWarehouses: Option[]; agents: Option[]; items: Option[]; today: string;
}) {
    const form = useForm<{ from_warehouse_id: string; to_warehouse_id: string; agent_customer_id: string; commission_percent: string; via_transit: boolean; dispatch_date: string; memo: string; lines: Line[] }>({
        from_warehouse_id: '', to_warehouse_id: '', agent_customer_id: '', commission_percent: '', via_transit: false, dispatch_date: today, memo: '', lines: [emptyLine()],
    });
    const lines = form.data.lines;
    const setLines = (n: Line[]) => form.setData('lines', n);
    const update = (i: number, f: keyof Line, v: string) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));
    const postingError = (form.errors as Record<string, string>).posting;
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/consignment/dispatches'); };

    return (
        <>
            <Head title="New Consignment Dispatch" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Consignment Dispatch</h1>
                    <Button type="submit" disabled={form.processing}>{form.data.via_transit ? 'Ship to transit' : 'Post dispatch'}</Button>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}
                <div className="grid gap-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>From warehouse</Label>
                        <Select value={form.data.from_warehouse_id} onValueChange={(v) => form.setData('from_warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Source" /></SelectTrigger>
                            <SelectContent>{sources.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.from_warehouse_id && <p className="text-destructive text-xs">{form.errors.from_warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>To consignment</Label>
                        <Select value={form.data.to_warehouse_id} onValueChange={(v) => form.setData('to_warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Consignment WH" /></SelectTrigger>
                            <SelectContent>{consignmentWarehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.to_warehouse_id && <p className="text-destructive text-xs">{form.errors.to_warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Agent (customer)</Label>
                        <Select value={form.data.agent_customer_id} onValueChange={(v) => form.setData('agent_customer_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                            <SelectContent>{agents.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="d">Dispatch date</Label>
                        <Input id="d" type="date" value={form.data.dispatch_date} onChange={(e) => form.setData('dispatch_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="comm">Agent commission</Label>
                        <div className="relative">
                            <Input id="comm" inputMode="decimal" value={form.data.commission_percent} onChange={(e) => form.setData('commission_percent', e.target.value)} className="pr-8 text-right font-mono" placeholder="0" />
                            <span className="text-muted-foreground pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm">%</span>
                        </div>
                        {form.errors.commission_percent && <p className="text-destructive text-xs">{form.errors.commission_percent}</p>}
                    </div>
                </div>

                <label className="flex w-fit cursor-pointer items-center gap-2.5 rounded-lg border bg-muted/20 px-3 py-2 text-sm">
                    <Checkbox checked={form.data.via_transit} onCheckedChange={(v) => form.setData('via_transit', v === true)} />
                    <span>Ship via <span className="font-medium">Goods in Transit</span> — receive on arrival before it can be settled</span>
                </label>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-[45%]">Item</th>
                                <th className="w-40 text-right">Quantity</th>
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
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.quantity} onChange={(e) => update(i, 'quantity', e.target.value)} placeholder="0" /></td>
                                    <td><Input value={line.description} onChange={(e) => update(i, 'description', e.target.value)} placeholder="—" /></td>
                                    <td className="text-center">{lines.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t"><tr><td colSpan={4} className="px-3 py-2.5"><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button></td></tr></tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">Posting moves stock to the consignment location at cost (Dr Inventory on Consignment / Cr source inventory). No sale is recognised until settlement.</p>
            </form>
        </>
    );
}
