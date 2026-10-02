import { Head, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Target, Trash2, TrendingDown, TrendingUp } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type Row = { id: number; account: string; type: string; cost_center: string | null; annual_budget: number; budget_ytd: number; actual_ytd: number; variance: number; variance_pct: number | null; favorable: boolean };
type Variance = { as_of: string; months: number; rows: Row[]; totals: { annual_budget: number; budget_ytd: number; actual_ytd: number; variance: number } };
type Budget = { id: number; name: string; fiscal_year: string; status: string };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });
const sum = (rows: Row[], key: keyof Row) => rows.reduce((a, r) => a + (r[key] as number), 0);

export default function BudgetingShow({ budget, variance, month, incomeAccounts, expenseAccounts, costCenters }: {
    budget: Budget; variance: Variance; month: number; incomeAccounts: Option[]; expenseAccounts: Option[]; costCenters: Option[];
}) {
    const { can } = usePermissions();
    const editable = can('budget.manage');
    const [addOpen, setAddOpen] = useState(false);

    const income = variance.rows.filter((r) => r.type === 'income');
    const expense = variance.rows.filter((r) => r.type === 'expense');

    const incActual = sum(income, 'actual_ytd'), incBudget = sum(income, 'budget_ytd');
    const expActual = sum(expense, 'actual_ytd'), expBudget = sum(expense, 'budget_ytd');
    const netActual = incActual - expActual, netBudget = incBudget - expBudget;

    const setMonth = (m: string) => router.get(`/budgeting/budgets/${budget.id}`, { month: m }, { preserveScroll: true, preserveState: true, only: ['variance', 'month'] });
    const removeLine = (lineId: number) => router.delete(`/budgeting/budgets/${budget.id}/lines/${lineId}`, { preserveScroll: true });

    const tiles = [
        { label: 'Income YTD', actual: incActual, budget: incBudget, from: '#10b981', to: '#0f766e', good: incActual >= incBudget },
        { label: 'Expenses YTD', actual: expActual, budget: expBudget, from: '#f43f5e', to: '#be185d', good: expActual <= expBudget },
        { label: 'Net Result YTD', actual: netActual, budget: netBudget, from: '#6366f1', to: '#4338ca', good: netActual >= netBudget },
    ];

    return (
        <>
            <Head title={`Budget — ${budget.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit('/budgeting/budgets')}><ArrowLeft className="size-4" /> All budgets</Button>
                    <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#7c3aed', '#c026d3')}>
                        <Target className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                        <div className="relative flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <div className="text-xs font-medium uppercase tracking-widest text-fuchsia-100">{budget.fiscal_year}</div>
                                <h1 className="mt-1 text-2xl font-bold tracking-tight">{budget.name}</h1>
                                <p className="mt-1 text-sm text-fuchsia-50/80">Actuals through {variance.as_of}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <div className="flex items-center gap-2 rounded-lg bg-white/15 px-3 py-1.5">
                                    <span className="text-xs text-fuchsia-50">As of month</span>
                                    <select value={month} onChange={(e) => setMonth(e.target.value)} className="bg-transparent text-sm font-semibold text-white outline-none [&>option]:text-slate-900">
                                        {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => <option key={m} value={m}>{m}</option>)}
                                    </select>
                                </div>
                                {editable && (
                                    <Dialog open={addOpen} onOpenChange={setAddOpen}>
                                        <DialogTrigger asChild><Button className="bg-white text-fuchsia-700 hover:bg-white/90"><Plus className="size-4" /> Budget line</Button></DialogTrigger>
                                        <LineDialog budgetId={budget.id} incomeAccounts={incomeAccounts} expenseAccounts={expenseAccounts} costCenters={costCenters} onDone={() => setAddOpen(false)} />
                                    </Dialog>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    {tiles.map((t) => (
                        <div key={t.label} className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(t.from, t.to)}>
                            <div className="text-xs font-medium uppercase tracking-wide text-white/80">{t.label}</div>
                            <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(t.actual)}</div>
                            <div className="mt-1 flex items-center gap-1.5 text-xs text-white/80">
                                {t.good ? <TrendingUp className="size-3.5" /> : <TrendingDown className="size-3.5" />}
                                <span>Budget {money(t.budget)}</span>
                            </div>
                        </div>
                    ))}
                </div>

                <VarianceTable title="Income" rows={income} editable={editable} onRemove={removeLine} />
                <VarianceTable title="Expenses" rows={expense} editable={editable} onRemove={removeLine} />
            </div>
        </>
    );
}

function VarianceTable({ title, rows, editable, onRemove }: { title: string; rows: Row[]; editable: boolean; onRemove: (id: number) => void }) {
    const tBudgetYtd = sum(rows, 'budget_ytd'), tActual = sum(rows, 'actual_ytd'), tAnnual = sum(rows, 'annual_budget');
    const tVar = tActual - tBudgetYtd;

    return (
        <div>
            <h2 className="mb-2 text-sm font-semibold text-muted-foreground">{title}</h2>
            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[820px] text-sm">
                    <thead className="bg-muted/50 text-muted-foreground">
                        <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                            <th className="text-left">Account</th>
                            <th className="text-left">Cost center</th>
                            <th className="text-right">Annual budget</th>
                            <th className="text-right">Budget YTD</th>
                            <th className="text-right">Actual YTD</th>
                            <th className="text-right">Variance</th>
                            <th className="text-right">%</th>
                            {editable && <th className="w-10"></th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.length === 0 && <tr><td colSpan={editable ? 8 : 7} className="text-muted-foreground px-4 py-8 text-center">No {title.toLowerCase()} lines.</td></tr>}
                        {rows.map((r) => (
                            <tr key={r.id} className="[&>td]:px-4 [&>td]:py-2.5">
                                <td className="font-medium">{r.account}</td>
                                <td className="text-muted-foreground text-xs">{r.cost_center ?? '—'}</td>
                                <td className="text-right font-mono tabular-nums">{money(r.annual_budget)}</td>
                                <td className="text-right font-mono tabular-nums">{money(r.budget_ytd)}</td>
                                <td className="text-right font-mono tabular-nums">{money(r.actual_ytd)}</td>
                                <td className={`text-right font-mono font-semibold tabular-nums ${r.favorable ? 'text-emerald-600' : 'text-rose-600'}`}>{money(r.variance)}</td>
                                <td className={`text-right font-mono text-xs tabular-nums ${r.favorable ? 'text-emerald-600' : 'text-rose-600'}`}>{r.variance_pct === null ? '—' : `${r.variance_pct}%`}</td>
                                {editable && <td className="text-center"><button onClick={() => onRemove(r.id)} className="text-muted-foreground/60 hover:text-rose-600"><Trash2 className="size-4" /></button></td>}
                            </tr>
                        ))}
                    </tbody>
                    {rows.length > 0 && (
                        <tfoot className="border-t-2">
                            <tr className="[&>td]:px-4 [&>td]:py-2.5 font-semibold">
                                <td colSpan={2}>Total</td>
                                <td className="text-right font-mono tabular-nums">{money(tAnnual)}</td>
                                <td className="text-right font-mono tabular-nums">{money(tBudgetYtd)}</td>
                                <td className="text-right font-mono tabular-nums">{money(tActual)}</td>
                                <td className={`text-right font-mono tabular-nums ${(title === 'Income' ? tVar >= 0 : tVar <= 0) ? 'text-emerald-600' : 'text-rose-600'}`}>{money(tVar)}</td>
                                <td colSpan={editable ? 2 : 1}></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
}

function LineDialog({ budgetId, incomeAccounts, expenseAccounts, costCenters, onDone }: {
    budgetId: number; incomeAccounts: Option[]; expenseAccounts: Option[]; costCenters: Option[]; onDone: () => void;
}) {
    const [type, setType] = useState('expense');
    const form = useForm({ account_id: '', cost_center_id: '', annual_amount: '' });
    const options = type === 'income' ? incomeAccounts : expenseAccounts;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/budgeting/budgets/${budgetId}/lines`, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;

    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>Set budget line</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-1.5">
                    <Label>Type</Label>
                    <Select value={type} onValueChange={(v) => { setType(v); form.setData('account_id', ''); }}>
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="expense">Expense</SelectItem>
                            <SelectItem value="income">Income</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5">
                    <Label>Account</Label>
                    <Select value={form.data.account_id} onValueChange={(v) => form.setData('account_id', v)}>
                        <SelectTrigger><SelectValue placeholder="Select account" /></SelectTrigger>
                        <SelectContent>{options.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                    </Select>
                    {err('account_id')}
                </div>
                <div className="grid gap-1.5">
                    <Label>Cost center (optional)</Label>
                    <Select value={form.data.cost_center_id} onValueChange={(v) => form.setData('cost_center_id', v)}>
                        <SelectTrigger><SelectValue placeholder="All cost centers" /></SelectTrigger>
                        <SelectContent>{costCenters.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.code} — {c.name}</SelectItem>)}</SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5"><Label>Annual budget amount</Label><Input inputMode="decimal" value={form.data.annual_amount} onChange={(e) => form.setData('annual_amount', e.target.value)} className="text-right font-mono" placeholder="0.00" />{err('annual_amount')}</div>
                <DialogFooter><Button type="submit" disabled={form.processing}>Save line</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}
