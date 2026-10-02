import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { money } from '@/lib/format';
import { scheduleLabel } from './index';

type Account = { id: number; code: string; name: string; type: string };
type CostCenter = { id: number; code: string; name: string };
type Line = { account_id: string; debit: string; credit: string; cost_center_id: string; description: string };

const emptyLine = (): Line => ({ account_id: '', debit: '', credit: '', cost_center_id: '', description: '' });

export default function CreateRecurring({ accounts, costCenters, frequencies, baseCurrency, today }: {
    accounts: Account[]; costCenters: CostCenter[]; frequencies: string[]; baseCurrency: string; today: string;
}) {
    const form = useForm<{
        name: string; reference: string; memo: string; frequency: string; interval: string;
        start_date: string; end_date: string; lines: Line[];
    }>({
        name: '', reference: '', memo: '', frequency: 'monthly', interval: '1',
        start_date: today, end_date: '', lines: [emptyLine(), emptyLine()],
    });

    const lines = form.data.lines;
    const setLines = (next: Line[]) => form.setData('lines', next);
    const updateLine = (i: number, field: keyof Line, value: string) => {
        setLines(lines.map((l, idx) => {
            if (idx !== i) return l;
            const updated = { ...l, [field]: value };
            if (field === 'debit' && value) updated.credit = '';
            if (field === 'credit' && value) updated.debit = '';
            return updated;
        }));
    };

    const totalDebit = lines.reduce((s, l) => s + (parseFloat(l.debit) || 0), 0);
    const totalCredit = lines.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0);
    const diff = Math.round((totalDebit - totalCredit) * 10000) / 10000;
    const balanced = diff === 0 && totalDebit > 0;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/accounting/recurring');
    };
    const postingError = (form.errors as Record<string, string>).posting;

    return (
        <>
            <Head title="New Recurring Journal" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button type="button" variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit('/accounting/recurring')}><ArrowLeft className="size-4" /> All templates</Button>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">New Recurring Journal</h1>
                        <div className="flex items-center gap-3">
                            <div className={`rounded-md px-3 py-1.5 text-sm font-medium tabular-nums ${balanced ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/15 text-amber-600 dark:text-amber-400'}`}>
                                {balanced ? 'Balanced' : `Out by ${money(Math.abs(diff))}`}
                            </div>
                            <Button type="submit" disabled={!balanced || form.processing}>Save template</Button>
                        </div>
                    </div>
                </div>

                {postingError && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{postingError}</div>}

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Monthly office rent" />{form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}</div>
                    <div className="grid gap-1.5"><Label>Reference</Label><Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} placeholder="Optional" /></div>
                    <div className="grid gap-1.5"><Label>Narration</Label><Input value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="Posted on each entry" /></div>
                </div>

                <div className="grid gap-4 rounded-xl border bg-muted/20 p-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Frequency</Label>
                        <Select value={form.data.frequency} onValueChange={(v) => form.setData('frequency', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{frequencies.map((f) => <SelectItem key={f} value={f} className="capitalize">{f}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5"><Label>Every</Label><Input inputMode="numeric" value={form.data.interval} onChange={(e) => form.setData('interval', e.target.value)} className="text-right font-mono" />{form.errors.interval && <p className="text-destructive text-xs">{form.errors.interval}</p>}</div>
                    <div className="grid gap-1.5"><Label>Start date</Label><Input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />{form.errors.start_date && <p className="text-destructive text-xs">{form.errors.start_date}</p>}</div>
                    <div className="grid gap-1.5"><Label>End date (optional)</Label><Input type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />{form.errors.end_date && <p className="text-destructive text-xs">{form.errors.end_date}</p>}</div>
                    <p className="text-muted-foreground col-span-full text-xs">Posts <span className="font-medium">{scheduleLabel(form.data.frequency, parseInt(form.data.interval) || 1)}</span> starting {form.data.start_date || '—'}.</p>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-[30%]">Account</th><th>Description</th><th className="w-40">Cost center</th>
                                <th className="w-32 text-right">Debit</th><th className="w-32 text-right">Credit</th><th className="w-10"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, i) => (
                                <tr key={i} className="[&>td]:px-3 [&>td]:py-1.5 align-top">
                                    <td>
                                        <Select value={line.account_id} onValueChange={(v) => updateLine(i, 'account_id', v)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="Select account" /></SelectTrigger>
                                            <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}><span className="font-mono text-xs">{a.code}</span> {a.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td><Input value={line.description} onChange={(e) => updateLine(i, 'description', e.target.value)} placeholder="—" /></td>
                                    <td>
                                        <Select value={line.cost_center_id} onValueChange={(v) => updateLine(i, 'cost_center_id', v)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="—" /></SelectTrigger>
                                            <SelectContent>{costCenters.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.debit} onChange={(e) => updateLine(i, 'debit', e.target.value)} placeholder="0.00" /></td>
                                    <td><Input inputMode="decimal" className="text-right font-mono tabular-nums" value={line.credit} onChange={(e) => updateLine(i, 'credit', e.target.value)} placeholder="0.00" /></td>
                                    <td className="text-center">{lines.length > 2 && <Button type="button" variant="ghost" size="icon" onClick={() => setLines(lines.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2">
                            <tr className="[&>td]:px-3 [&>td]:py-2.5 font-medium">
                                <td colSpan={3}><Button type="button" variant="outline" size="sm" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-4" /> Add line</Button></td>
                                <td className="text-right font-mono tabular-nums">{money(totalDebit)}</td>
                                <td className="text-right font-mono tabular-nums">{money(totalCredit)}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">Amounts in {baseCurrency}. Each line takes either a debit or a credit; the template must balance before it can be saved.</p>
            </form>
        </>
    );
}
