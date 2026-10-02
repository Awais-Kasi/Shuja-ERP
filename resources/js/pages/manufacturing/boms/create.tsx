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
type Line = { component_item_id: string; quantity: string };
const emptyLine = (): Line => ({ component_item_id: '', quantity: '' });

export default function CreateBom({ items }: { items: Item[] }) {
    const form = useForm<{ code: string; name: string; item_id: string; output_qty: string; lines: Line[] }>({
        code: '', name: '', item_id: '', output_qty: '1', lines: [emptyLine(), emptyLine()],
    });
    const lines = form.data.lines;
    const setLines = (n: Line[]) => form.setData('lines', n);
    const update = (i: number, f: keyof Line, v: string) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));

    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/manufacturing/boms'); };

    return (
        <>
            <Head title="New BOM" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">New Bill of Material</h1>
                    <Button type="submit" disabled={form.processing}>Create BOM</Button>
                </div>
                <div className="grid gap-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="code">Code</Label>
                        <Input id="code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                        {form.errors.code && <p className="text-destructive text-xs">{form.errors.code}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Produces (output item)</Label>
                        <Select value={form.data.item_id} onValueChange={(v) => form.setData('item_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                            <SelectContent>{items.map((it) => <SelectItem key={it.id} value={String(it.id)}>{it.code} — {it.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.item_id && <p className="text-destructive text-xs">{form.errors.item_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="oq">Batch output qty</Label>
                        <Input id="oq" inputMode="decimal" value={form.data.output_qty} onChange={(e) => form.setData('output_qty', e.target.value)} />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-[60%]">Component</th>
                                <th className="w-40 text-right">Quantity / batch</th>
                                <th className="w-10"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => (
                                <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-top">
                                    <td>
                                        <Select value={line.component_item_id} onValueChange={(v) => update(i, 'component_item_id', v)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="Select component" /></SelectTrigger>
                                            <SelectContent>{items.map((it) => <SelectItem key={it.id} value={String(it.id)}><span className="font-mono text-xs">{it.code}</span> {it.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.quantity} onChange={(e) => update(i, 'quantity', e.target.value)} placeholder="0" /></td>
                                    <td className="text-center">{lines.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t"><tr><td colSpan={3} className="px-3 py-2.5"><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add component</Button></td></tr></tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">Component quantities are per batch of {form.data.output_qty || 1} output unit(s); work orders scale them automatically.</p>
            </form>
        </>
    );
}
