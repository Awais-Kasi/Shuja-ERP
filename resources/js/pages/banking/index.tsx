import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Landmark, Plus, Scale } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type AccountOption = { id: number; label: string; book_balance: number };
type Reconciliation = {
    id: number; account: string; statement_date: string; statement_balance: number;
    reconciled: number; difference: number; status: string; cleared_count: number;
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function BankingIndex({ reconciliations, accounts, today }: {
    reconciliations: Reconciliation[]; accounts: AccountOption[]; today: string;
}) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="Bank Reconciliation" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#0f766e', '#0891b2')}>
                    <Landmark className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-teal-100">Finance</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Bank Reconciliation</h1>
                            <p className="mt-1 text-sm text-teal-50/80">Tie each bank statement to the ledger, clearing items and posting charges & interest.</p>
                        </div>
                        {can('banking.reconcile') && accounts.length > 0 && (
                            <Dialog open={open} onOpenChange={setOpen}>
                                <DialogTrigger asChild><Button className="bg-white text-teal-700 hover:bg-white/90"><Plus className="size-4" /> New reconciliation</Button></DialogTrigger>
                                <StartDialog accounts={accounts} today={today} onDone={() => setOpen(false)} />
                            </Dialog>
                        )}
                    </div>
                </div>

                {accounts.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {accounts.map((a) => (
                            <div key={a.id} className="rounded-2xl border bg-card p-4 shadow-sm">
                                <div className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{a.label}</div>
                                <div className="mt-1 flex items-baseline justify-between">
                                    <span className="text-muted-foreground text-xs">Book balance</span>
                                    <span className="font-mono text-lg font-semibold tabular-nums">{money(a.book_balance)}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Account</th>
                                <th className="text-left">Statement date</th>
                                <th className="text-right">Statement balance</th>
                                <th className="text-right">Reconciled</th>
                                <th className="text-right">Difference</th>
                                <th className="text-center">Items</th>
                                <th className="text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {reconciliations.length === 0 && <tr><td colSpan={7} className="text-muted-foreground px-4 py-10 text-center">No reconciliations yet.</td></tr>}
                            {reconciliations.map((r) => (
                                <tr key={r.id} onClick={() => router.visit(`/banking/reconciliations/${r.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{r.account}</td>
                                    <td className="font-mono text-xs">{r.statement_date}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.statement_balance)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.reconciled)}</td>
                                    <td className={`text-right font-mono font-semibold tabular-nums ${Math.abs(r.difference) < 0.005 ? 'text-emerald-600' : 'text-rose-600'}`}>{money(r.difference)}</td>
                                    <td className="text-center tabular-nums">{r.cleared_count}</td>
                                    <td>
                                        <Badge variant="secondary" className={r.status === 'completed' ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/15 text-amber-600 dark:text-amber-400'}>
                                            {r.status === 'completed' ? <CheckCircle2 className="mr-1 size-3" /> : <Scale className="mr-1 size-3" />}
                                            <span className="capitalize">{r.status}</span>
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function StartDialog({ accounts, today, onDone }: { accounts: AccountOption[]; today: string; onDone: () => void }) {
    const form = useForm({ bank_account_id: '', statement_date: today, statement_balance: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/banking/reconciliations', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;

    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>Start a reconciliation</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-1.5">
                    <Label>Bank / cash account</Label>
                    <Select value={form.data.bank_account_id} onValueChange={(v) => form.setData('bank_account_id', v)}>
                        <SelectTrigger><SelectValue placeholder="Select account" /></SelectTrigger>
                        <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.label}</SelectItem>)}</SelectContent>
                    </Select>
                    {err('bank_account_id')}
                </div>
                <div className="grid gap-1.5"><Label>Statement date</Label><Input type="date" value={form.data.statement_date} onChange={(e) => form.setData('statement_date', e.target.value)} />{err('statement_date')}</div>
                <div className="grid gap-1.5">
                    <Label>Statement ending balance</Label>
                    <Input inputMode="decimal" value={form.data.statement_balance} onChange={(e) => form.setData('statement_balance', e.target.value)} className="text-right font-mono" placeholder="0.00" />
                    {err('statement_balance')}
                </div>
                <DialogFooter><Button type="submit" disabled={form.processing}>Start</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}
