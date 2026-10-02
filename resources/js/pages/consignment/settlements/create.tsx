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
import { money } from '@/lib/format';

type DispatchLine = { dispatch_line_id: number; item_id: number; item_label: string; remaining: number };
type Dispatch = { id: number; number: string; consignment_warehouse_id: number; agent_customer_id: number | null; lines: DispatchLine[] };
type Warehouse = { id: number; code: string; name: string };
type AccountOption = { id: number; code: string; name: string };
type Line = { consignment_dispatch_line_id: number; item_id: number; item_label: string; remaining: number; sold_qty: string; rate: string; returned_qty: string };

export default function CreateSettlement({ dispatch, returnWarehouses, fundingAccounts, today }: { dispatch: Dispatch; returnWarehouses: Warehouse[]; fundingAccounts: AccountOption[]; today: string }) {
    const form = useForm<{
        consignment_dispatch_id: number; consignment_warehouse_id: number; agent_customer_id: number | null;
        return_warehouse_id: string; settlement_date: string; tax_amount: string; return_freight: string; return_freight_account_id: string; memo: string; lines: Line[];
    }>({
        consignment_dispatch_id: dispatch.id,
        consignment_warehouse_id: dispatch.consignment_warehouse_id,
        agent_customer_id: dispatch.agent_customer_id,
        return_warehouse_id: '',
        settlement_date: today,
        tax_amount: '',
        return_freight: '',
        return_freight_account_id: '',
        memo: '',
        lines: dispatch.lines.map((l) => ({
            consignment_dispatch_line_id: l.dispatch_line_id, item_id: l.item_id, item_label: l.item_label,
            remaining: l.remaining, sold_qty: '', rate: '', returned_qty: '',
        })),
    });

    const lines = form.data.lines;
    const update = (i: number, f: keyof Line, v: string) => form.setData('lines', lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));
    const subtotal = lines.reduce((s, l) => s + (parseFloat(l.sold_qty) || 0) * (parseFloat(l.rate) || 0), 0);
    const tax = parseFloat(form.data.tax_amount) || 0;
    const postingError = (form.errors as Record<string, string>).posting;
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/consignment/settlements'); };

    return (
        <>
            <Head title="Settle Consignment" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Settle Consignment</h1>
                        <p className="text-muted-foreground text-sm">Dispatch <span className="font-mono">{dispatch.number}</span></p>
                    </div>
                    <Button type="submit" disabled={form.processing}>Post settlement</Button>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-1.5">
                        <Label>Return warehouse (for unsold)</Label>
                        <Select value={form.data.return_warehouse_id} onValueChange={(v) => form.setData('return_warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Default: dispatch source" /></SelectTrigger>
                            <SelectContent>{returnWarehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="d">Settlement date</Label>
                        <Input id="d" type="date" value={form.data.settlement_date} onChange={(e) => form.setData('settlement_date', e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="tax">Output tax</Label>
                        <Input id="tax" inputMode="decimal" value={form.data.tax_amount} onChange={(e) => form.setData('tax_amount', e.target.value)} placeholder="0.00" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="freight">Return freight (optional)</Label>
                        <Input id="freight" inputMode="decimal" value={form.data.return_freight} onChange={(e) => form.setData('return_freight', e.target.value)} placeholder="0.00" />
                        {form.errors.return_freight && <p className="text-destructive text-xs">{form.errors.return_freight}</p>}
                    </div>
                    {(parseFloat(form.data.return_freight) || 0) > 0 && (
                        <div className="grid gap-1.5">
                            <Label>Freight paid from</Label>
                            <Select value={form.data.return_freight_account_id} onValueChange={(v) => form.setData('return_freight_account_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Cash / bank / payable" /></SelectTrigger>
                                <SelectContent>{fundingAccounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {form.errors.return_freight_account_id && <p className="text-destructive text-xs">{form.errors.return_freight_account_id}</p>}
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Item</th>
                                <th className="w-24 text-right">Remaining</th>
                                <th className="w-28 text-right">Sold qty</th>
                                <th className="w-28 text-right">Sell rate</th>
                                <th className="w-28 text-right">Returned qty</th>
                                <th className="w-28 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => (
                                <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-top">
                                    <td className="pt-3">{line.item_label}</td>
                                    <td className="pt-3 text-right font-mono tabular-nums text-muted-foreground">{money(line.remaining)}</td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.sold_qty} onChange={(e) => update(i, 'sold_qty', e.target.value)} placeholder="0" /></td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.rate} onChange={(e) => update(i, 'rate', e.target.value)} placeholder="0.00" /></td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.returned_qty} onChange={(e) => update(i, 'returned_qty', e.target.value)} placeholder="0" /></td>
                                    <td className="pt-3 text-right font-mono tabular-nums text-muted-foreground">{money((parseFloat(line.sold_qty) || 0) * (parseFloat(line.rate) || 0))}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-col items-end gap-1.5 text-sm">
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Subtotal</span><span className="font-mono tabular-nums">{money(subtotal)}</span></div>
                    <div className="flex w-64 justify-between"><span className="text-muted-foreground">Output tax</span><span className="font-mono tabular-nums">{money(tax)}</span></div>
                    <div className="flex w-64 justify-between border-t pt-1.5 text-base font-semibold"><span>Total receivable</span><span className="font-mono tabular-nums">{money(subtotal + tax)}</span></div>
                    {(parseFloat(form.data.return_freight) || 0) > 0 && (
                        <div className="flex w-64 justify-between text-xs text-muted-foreground"><span>+ Return freight (company cost)</span><span className="font-mono tabular-nums">{money(parseFloat(form.data.return_freight) || 0)}</span></div>
                    )}
                </div>
                <p className="text-muted-foreground text-xs">Sold quantities recognise revenue + COGS from the consignment stock; returned quantities move back to the return warehouse at cost.</p>
            </form>
        </>
    );
}
