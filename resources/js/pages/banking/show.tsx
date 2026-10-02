import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Landmark, Lock, Plus, RotateCcw } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type Line = { id: number; date: string; journal_id: number; journal_number: string; type: string; description: string | null; reference: string | null; amount: number; cleared: boolean };
type Reconciliation = {
    id: number; account: string; statement_date: string; opening_balance: number; statement_balance: number;
    cleared_total: number; reconciled: number; difference: number; status: string; book_balance: number;
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function BankingShow({ reconciliation, lines, contraAccounts, defaults, today }: {
    reconciliation: Reconciliation;
    lines: Line[];
    contraAccounts: { expense: Option[]; income: Option[] };
    defaults: { charge_account_id: number | null; credit_account_id: number | null };
    today: string;
}) {
    const { can } = usePermissions();
    const editable = reconciliation.status === 'draft' && can('banking.reconcile');
    const balanced = Math.abs(reconciliation.difference) < 0.005;
    const [adjustOpen, setAdjustOpen] = useState(false);

    const toggle = (lineId: number) => {
        if (!editable) return;
        router.post(`/banking/reconciliations/${reconciliation.id}/toggle`, { journal_line_id: lineId }, { preserveScroll: true });
    };

    const act = (verb: 'complete' | 'reopen') => {
        router.post(`/banking/reconciliations/${reconciliation.id}/${verb}`, {}, { preserveScroll: true });
    };

    const tiles = [
        { label: 'Statement balance', value: money(reconciliation.statement_balance), from: '#0f766e', to: '#0891b2' },
        { label: 'Reconciled (books)', value: money(reconciliation.reconciled), from: '#6366f1', to: '#4338ca' },
        { label: 'Difference', value: money(reconciliation.difference), from: balanced ? '#10b981' : '#f43f5e', to: balanced ? '#0f766e' : '#be185d' },
        { label: 'GL book balance', value: money(reconciliation.book_balance), from: '#334155', to: '#0f172a' },
    ];

    return (
        <>
            <Head title={`Reconciliation — ${reconciliation.account}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit('/banking/reconciliations')}><ArrowLeft className="size-4" /> All reconciliations</Button>
                    <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#0f766e', '#0891b2')}>
                        <Landmark className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                        <div className="relative flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <div className="text-xs font-medium uppercase tracking-widest text-teal-100">Statement to {reconciliation.statement_date}</div>
                                <h1 className="mt-1 text-2xl font-bold tracking-tight">{reconciliation.account}</h1>
                                <p className="mt-1 text-sm text-teal-50/80">Opening cleared balance {money(reconciliation.opening_balance)}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge variant="secondary" className={reconciliation.status === 'completed' ? 'bg-white/20 text-white' : 'bg-white/20 text-white'}>
                                    <span className="capitalize">{reconciliation.status}</span>
                                </Badge>
                                {editable && (
                                    <Dialog open={adjustOpen} onOpenChange={setAdjustOpen}>
                                        <DialogTrigger asChild><Button variant="secondary" className="bg-white/15 text-white hover:bg-white/25"><Plus className="size-4" /> Adjustment</Button></DialogTrigger>
                                        <AdjustDialog reconciliationId={reconciliation.id} contraAccounts={contraAccounts} defaults={defaults} today={today} onDone={() => setAdjustOpen(false)} />
                                    </Dialog>
                                )}
                                {editable && <Button className="bg-white text-teal-700 hover:bg-white/90 disabled:opacity-50" disabled={!balanced} onClick={() => act('complete')}><CheckCircle2 className="size-4" /> Complete</Button>}
                                {reconciliation.status === 'completed' && can('banking.reconcile') && <Button variant="secondary" className="bg-white/15 text-white hover:bg-white/25" onClick={() => act('reopen')}><RotateCcw className="size-4" /> Reopen</Button>}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {tiles.map((t) => (
                        <div key={t.label} className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(t.from, t.to)}>
                            <div className="text-xs font-medium uppercase tracking-wide text-white/80">{t.label}</div>
                            <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{t.value}</div>
                        </div>
                    ))}
                </div>

                {balanced && reconciliation.status === 'draft' && (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-400">
                        <CheckCircle2 className="size-4" /> This reconciliation ties out to the statement. You can complete it.
                    </div>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[820px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="w-12 text-center">Cleared</th>
                                <th className="text-left">Date</th>
                                <th className="text-left">Journal</th>
                                <th className="text-left">Description</th>
                                <th className="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No unreconciled bank entries.</td></tr>}
                            {lines.map((l) => (
                                <tr key={l.id} className={`[&>td]:px-4 [&>td]:py-2.5 ${l.cleared ? 'bg-emerald-500/5' : ''}`}>
                                    <td className="text-center">
                                        {editable
                                            ? <Checkbox checked={l.cleared} onCheckedChange={() => toggle(l.id)} />
                                            : (l.cleared ? <CheckCircle2 className="mx-auto size-4 text-emerald-600" /> : <Lock className="text-muted-foreground/40 mx-auto size-4" />)}
                                    </td>
                                    <td className="font-mono text-xs">{l.date}</td>
                                    <td>
                                        <button onClick={() => router.visit(`/accounting/journals/${l.journal_id}`)} className="font-mono text-xs text-teal-600 hover:underline">{l.journal_number}</button>
                                    </td>
                                    <td>{l.description ?? '—'}</td>
                                    <td className={`text-right font-mono font-semibold tabular-nums ${l.amount >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>{money(l.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function AdjustDialog({ reconciliationId, contraAccounts, defaults, today, onDone }: {
    reconciliationId: number; contraAccounts: { expense: Option[]; income: Option[] }; defaults: { charge_account_id: number | null; credit_account_id: number | null }; today: string; onDone: () => void;
}) {
    const form = useForm({
        kind: 'charge',
        amount: '',
        counter_account_id: defaults.charge_account_id ? String(defaults.charge_account_id) : '',
        date: today,
        memo: '',
    });

    const setKind = (kind: string) => {
        const fallback = kind === 'charge' ? defaults.charge_account_id : defaults.credit_account_id;
        form.setData('kind', kind);
        form.setData('counter_account_id', fallback ? String(fallback) : '');
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/banking/reconciliations/${reconciliationId}/adjust`, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;
    const options = form.data.kind === 'charge' ? contraAccounts.expense : contraAccounts.income;

    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>Post a statement adjustment</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-1.5">
                    <Label>Type</Label>
                    <Select value={form.data.kind} onValueChange={setKind}>
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="charge">Bank charge (money out)</SelectItem>
                            <SelectItem value="credit">Interest / credit (money in)</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5"><Label>Amount</Label><Input inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className="text-right font-mono" placeholder="0.00" />{err('amount')}</div>
                <div className="grid gap-1.5">
                    <Label>{form.data.kind === 'charge' ? 'Expense account' : 'Income account'}</Label>
                    <Select value={form.data.counter_account_id} onValueChange={(v) => form.setData('counter_account_id', v)}>
                        <SelectTrigger><SelectValue placeholder="Select account" /></SelectTrigger>
                        <SelectContent>{options.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                    </Select>
                    {err('counter_account_id')}
                </div>
                <div className="grid gap-1.5"><Label>Date</Label><Input type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />{err('date')}</div>
                <div className="grid gap-1.5"><Label>Memo</Label><Input value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="Optional" />{err('memo')}</div>
                {form.errors.adjust && <p className="text-destructive text-xs">{form.errors.adjust}</p>}
                <DialogFooter><Button type="submit" disabled={form.processing}>Post adjustment</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}
