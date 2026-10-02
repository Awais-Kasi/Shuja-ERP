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
import { money } from '@/lib/format';

type Dispatch = { id: number; number: string; warehouse: string; warehouse_id: number; items: string[] };
type Account = { id: number; label: string };
type Line = { expense_type: string; amount: string; capitalise: boolean };
const emptyLine = (): Line => ({ expense_type: 'freight', amount: '', capitalise: true });
const TYPES = ['freight', 'transport', 'loading', 'labour', 'other'];

export default function CreateExpense({ dispatch, accounts, today }: { dispatch: Dispatch; accounts: Account[]; today: string }) {
    const form = useForm<{ consignment_dispatch_id: number; warehouse_id: number; credit_account_id: string; expense_date: string; allocation_basis: string; memo: string; lines: Line[] }>({
        consignment_dispatch_id: dispatch.id,
        warehouse_id: dispatch.warehouse_id,
        credit_account_id: '',
        expense_date: today,
        allocation_basis: 'value',
        memo: '',
        lines: [emptyLine()],
    });
    const lines = form.data.lines;
    const setLines = (n: Line[]) => form.setData('lines', n);
    const update = (i: number, f: keyof Line, v: string | boolean) => setLines(lines.map((l, idx) => (idx === i ? { ...l, [f]: v } : l)));
    const capTotal = lines.filter((l) => l.capitalise).reduce((s, l) => s + (parseFloat(l.amount) || 0), 0);
    const total = lines.reduce((s, l) => s + (parseFloat(l.amount) || 0), 0);
    const postingError = (form.errors as Record<string, string>).posting;
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/consignment/expenses'); };

    return (
        <>
            <Head title="Add Consignment Expense" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Add Consignment Expense</h1>
                        <p className="text-muted-foreground text-sm">Dispatch <span className="font-mono">{dispatch.number}</span> · capitalise onto {dispatch.warehouse}</p>
                    </div>
                    <Button type="submit" disabled={form.processing}>Post expense</Button>
                </div>
                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-1.5">
                        <Label>Paid from / credit</Label>
                        <Select value={form.data.credit_account_id} onValueChange={(v) => form.setData('credit_account_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Cash / Bank / Payable" /></SelectTrigger>
                            <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.label}</SelectItem>)}</SelectContent>
                        </Select>
                        {form.errors.credit_account_id && <p className="text-destructive text-xs">{form.errors.credit_account_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Allocation basis</Label>
                        <Select value={form.data.allocation_basis} onValueChange={(v) => form.setData('allocation_basis', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value="value">By value</SelectItem><SelectItem value="quantity">By quantity</SelectItem></SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="d">Date</Label>
                        <Input id="d" type="date" value={form.data.expense_date} onChange={(e) => form.setData('expense_date', e.target.value)} />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-48">Type</th>
                                <th className="w-40 text-right">Amount</th>
                                <th className="w-40 text-center">Capitalise?</th>
                                <th className="w-10"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => (
                                <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-middle">
                                    <td>
                                        <Select value={line.expense_type} onValueChange={(v) => update(i, 'expense_type', v)}>
                                            <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                            <SelectContent>{TYPES.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.amount} onChange={(e) => update(i, 'amount', e.target.value)} placeholder="0.00" /></td>
                                    <td className="text-center"><Checkbox checked={line.capitalise} onCheckedChange={(v) => update(i, 'capitalise', Boolean(v))} /></td>
                                    <td className="text-center">{lines.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t"><tr><td colSpan={4} className="px-3 py-2.5"><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button></td></tr></tfoot>
                    </table>
                </div>
                <div className="text-muted-foreground text-sm">Capitalised onto stock: <span className="text-foreground font-mono">{money(capTotal)}</span> · Total: <span className="text-foreground font-mono">{money(total)}</span></div>
                <p className="text-muted-foreground text-xs">Capitalised lines raise the consignment stock value (allocated across items); non-capitalised lines go straight to P&L.</p>
            </form>
        </>
    );
}
