import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Coins, Plus, TrendingDown, TrendingUp } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Row = { account_id: number; code: string; name: string; currency: string; foreign_balance: number; closing_rate: number | null; carrying_base: number; revalued_base: number | null; adjustment: number | null; missing_rate: boolean };
type Rate = { currency: string; rate: number; date: string };
type Reval = { id: number; date: string; gain: number; loss: number; journal_id: number | null };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });
const rate4 = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });

export default function FxIndex({ date, baseCurrency, rows, foreignCurrencies, rates, revaluations, today }: {
    date: string; baseCurrency: string; rows: Row[]; foreignCurrencies: { code: string; name: string }[]; rates: Rate[]; revaluations: Reval[]; today: string;
}) {
    const { can } = usePermissions();
    const manage = can('accounting.fx.manage');
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [rateOpen, setRateOpen] = useState(false);

    const missing = rows.some((r) => r.missing_rate && Math.abs(r.foreign_balance) > 0.0001);
    const effective = rows.filter((r) => r.adjustment !== null && Math.abs(r.adjustment) > 0.0001);
    const totalGain = effective.filter((r) => (r.adjustment ?? 0) > 0).reduce((s, r) => s + (r.adjustment ?? 0), 0);
    const totalLoss = effective.filter((r) => (r.adjustment ?? 0) < 0).reduce((s, r) => s - (r.adjustment ?? 0), 0);

    const setDate = (d: string) => router.get('/accounting/fx-revaluation', { date: d }, { preserveScroll: true, preserveState: true, only: ['rows', 'date', 'rates'] });
    const run = () => router.post('/accounting/fx-revaluation', { date }, { preserveScroll: true });

    return (
        <>
            <Head title="FX Revaluation" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#0369a1', '#0e7490')}>
                    <Coins className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-sky-100">Accounting · Period-end</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">FX Revaluation</h1>
                            <p className="mt-1 text-sm text-sky-50/80">Revalue open {baseCurrency} monetary balances held in foreign currencies to the closing rate.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="flex items-center gap-2 rounded-lg bg-white/15 px-3 py-1.5">
                                <span className="text-xs text-sky-50">As of</span>
                                <input type="date" value={date} max={today} onChange={(e) => setDate(e.target.value)} className="bg-transparent text-sm font-semibold text-white outline-none [color-scheme:dark]" />
                            </div>
                            {manage && <Button className="bg-white text-sky-700 hover:bg-white/90 disabled:opacity-50" disabled={missing || effective.length === 0} onClick={run}>Post revaluation</Button>}
                        </div>
                    </div>
                </div>

                {errors.run && <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-400"><AlertTriangle className="size-4" /> {errors.run}</div>}
                {missing && <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-400"><AlertTriangle className="size-4" /> Some balances have no closing rate on or before {date}. Add a rate below before posting.</div>}

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient('#10b981', '#0f766e')}>
                        <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-white/80"><TrendingUp className="size-3.5" /> Unrealised gain</div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(totalGain)}</div>
                    </div>
                    <div className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient('#f43f5e', '#be185d')}>
                        <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-white/80"><TrendingDown className="size-3.5" /> Unrealised loss</div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(totalLoss)}</div>
                    </div>
                    <div className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient('#6366f1', '#4338ca')}>
                        <div className="text-xs font-medium uppercase tracking-wide text-white/80">Net adjustment</div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(totalGain - totalLoss)}</div>
                    </div>
                </div>

                <div>
                    <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Open foreign-currency balances as of {date}</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[880px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                    <th className="text-left">Account</th><th className="text-left">Ccy</th>
                                    <th className="text-right">Foreign balance</th><th className="text-right">Closing rate</th>
                                    <th className="text-right">Carrying ({baseCurrency})</th><th className="text-right">Revalued ({baseCurrency})</th><th className="text-right">Adjustment</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.length === 0 && <tr><td colSpan={7} className="text-muted-foreground px-4 py-10 text-center">No foreign-currency monetary balances.</td></tr>}
                                {rows.map((r) => (
                                    <tr key={`${r.account_id}-${r.currency}`} className="[&>td]:px-4 [&>td]:py-2.5">
                                        <td className="font-medium">{r.code} — {r.name}</td>
                                        <td className="font-mono text-xs">{r.currency}</td>
                                        <td className="text-right font-mono tabular-nums">{money(r.foreign_balance)}</td>
                                        <td className="text-right font-mono tabular-nums">{r.closing_rate === null ? <span className="text-amber-600">no rate</span> : rate4(r.closing_rate)}</td>
                                        <td className="text-right font-mono tabular-nums">{money(r.carrying_base)}</td>
                                        <td className="text-right font-mono tabular-nums">{r.revalued_base === null ? '—' : money(r.revalued_base)}</td>
                                        <td className={`text-right font-mono font-semibold tabular-nums ${r.adjustment === null ? '' : (r.adjustment >= 0 ? 'text-emerald-600' : 'text-rose-600')}`}>{r.adjustment === null ? '—' : money(r.adjustment)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-muted-foreground">Exchange rates ({baseCurrency} per unit)</h2>
                            {manage && (
                                <Dialog open={rateOpen} onOpenChange={setRateOpen}>
                                    <DialogTrigger asChild><Button size="sm" variant="outline"><Plus className="size-4" /> Add rate</Button></DialogTrigger>
                                    <RateDialog currencies={foreignCurrencies} today={today} onDone={() => setRateOpen(false)} />
                                </Dialog>
                            )}
                        </div>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Currency</th><th className="text-right">Rate</th><th className="text-left">Date</th></tr></thead>
                                <tbody className="divide-y">
                                    {rates.length === 0 && <tr><td colSpan={3} className="text-muted-foreground px-4 py-8 text-center">No rates yet.</td></tr>}
                                    {rates.map((r, i) => (
                                        <tr key={i} className="[&>td]:px-4 [&>td]:py-2"><td className="font-mono text-xs">{r.currency}</td><td className="text-right font-mono tabular-nums">{rate4(r.rate)}</td><td className="font-mono text-xs">{r.date}</td></tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Past revaluations</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">Date</th><th className="text-right">Gain</th><th className="text-right">Loss</th></tr></thead>
                                <tbody className="divide-y">
                                    {revaluations.length === 0 && <tr><td colSpan={3} className="text-muted-foreground px-4 py-8 text-center">None yet.</td></tr>}
                                    {revaluations.map((r) => (
                                        <tr key={r.id} onClick={() => router.visit(`/accounting/fx-revaluation/${r.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2">
                                            <td className="font-mono text-xs">{r.date}</td>
                                            <td className="text-right font-mono tabular-nums text-emerald-600">{money(r.gain)}</td>
                                            <td className="text-right font-mono tabular-nums text-rose-600">{money(r.loss)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

function RateDialog({ currencies, today, onDone }: { currencies: { code: string; name: string }[]; today: string; onDone: () => void }) {
    const form = useForm({ quote_code: '', rate: '', rate_date: today });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/accounting/fx-revaluation/rates', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;

    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>Add exchange rate</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-1.5">
                    <Label>Currency</Label>
                    <Select value={form.data.quote_code} onValueChange={(v) => form.setData('quote_code', v)}>
                        <SelectTrigger><SelectValue placeholder="Select currency" /></SelectTrigger>
                        <SelectContent>{currencies.map((c) => <SelectItem key={c.code} value={c.code}>{c.code} — {c.name}</SelectItem>)}</SelectContent>
                    </Select>
                    {err('quote_code')}
                </div>
                <div className="grid gap-1.5"><Label>Rate (base per 1 unit)</Label><Input inputMode="decimal" value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} className="text-right font-mono" placeholder="0.0000" />{err('rate')}</div>
                <div className="grid gap-1.5"><Label>Rate date</Label><Input type="date" value={form.data.rate_date} onChange={(e) => form.setData('rate_date', e.target.value)} />{err('rate_date')}</div>
                <DialogFooter><Button type="submit" disabled={form.processing}>Save rate</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}
