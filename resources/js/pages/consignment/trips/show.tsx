import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, HandCoins, PlayCircle, Route, TrendingDown, TrendingUp } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type AccountOption = Option & { control_type: string | null; type: string };
type Step = { sequence: number; type: string; label: string | null; location: string | null; vehicle_no: string | null; basis: string; rate: number; amount: number; status: string };
type Trip = {
    id: number; number: string | null; date: string; vehicle_no: string | null; source: string;
    item: string | null; warehouse: string | null; supplier: string | null; customer: string | null;
    origin: string | null; destination: string | null; quantity: number; packages: number;
    goods_rate: number; goods_cost: number; logistics_cost: number; total_cost: number; cost_per_unit: number;
    sale_amount: number | null; cogs_total: number | null; profit: number | null; status: string; memo: string | null;
    journal_id: number | null; settlement_journal_id: number | null; can_post: boolean; can_settle: boolean; steps: Step[];
};

const basisLabel: Record<string, string> = { per_bag: 'per bag', per_kg: 'per kg/unit', flat: 'flat' };
const statusStyle: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    posted: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    settled: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
};

export default function ShowTrip({ trip, customers, accounts }: { trip: Trip; customers: Option[]; accounts: AccountOption[] }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [settleOpen, setSettleOpen] = useState(false);

    const settleForm = useForm<{ sale_amount: string; customer_id: string; sale_account_id: string; settlement_date: string }>({
        sale_amount: '', customer_id: '', sale_account_id: '', settlement_date: trip.date,
    });

    const post = () => {
        if (confirm('Post this trip? Every leg cost will be capitalised onto the goods (landed costing).')) {
            router.post(`/consignment/trips/${trip.id}/post`, {}, { preserveScroll: true });
        }
    };
    const submitSettle = (e: FormEvent) => {
        e.preventDefault();
        settleForm.post(`/consignment/trips/${trip.id}/settle`, { onSuccess: () => setSettleOpen(false) });
    };

    const projectedProfit = (parseFloat(settleForm.data.sale_amount) || 0) - trip.total_cost;

    return (
        <>
            <Head title={`Trip ${trip.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/consignment/trips"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                                <Route className="size-5" />{trip.number}
                                <Badge variant="secondary" className={`capitalize ${statusStyle[trip.status] ?? ''}`}>{trip.status}</Badge>
                            </h1>
                            <p className="text-muted-foreground text-sm">
                                {trip.item}{(trip.origin || trip.destination) ? ` · ${trip.origin ?? ''}${trip.destination ? ' → ' + trip.destination : ''}` : ''}
                                {trip.vehicle_no ? ` · 🚚 ${trip.vehicle_no}` : ''} · {trip.source === 'purchase' ? 'Purchased' : 'Manufactured'}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {trip.journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${trip.journal_id}`}>Posting journal</Link></Button>}
                        {trip.settlement_journal_id && <Button asChild variant="outline"><Link href={`/accounting/journals/${trip.settlement_journal_id}`}>Sale journal</Link></Button>}
                        {trip.can_post && can('consignment.manage') && <Button onClick={post}><PlayCircle className="size-4" /> Post trip</Button>}
                        {trip.can_settle && can('consignment.settle') && (
                            <Dialog open={settleOpen} onOpenChange={setSettleOpen}>
                                <DialogTrigger asChild><Button><HandCoins className="size-4" /> Settle (sell)</Button></DialogTrigger>
                                <DialogContent>
                                    <form onSubmit={submitSettle}>
                                        <DialogHeader><DialogTitle>Settle trip {trip.number}</DialogTitle></DialogHeader>
                                        <div className="grid gap-4 py-4">
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="sale">Total sale amount</Label>
                                                <Input id="sale" inputMode="decimal" className="text-right font-mono" value={settleForm.data.sale_amount} onChange={(e) => settleForm.setData('sale_amount', e.target.value)} placeholder="0" autoFocus />
                                                {settleForm.errors.sale_amount && <p className="text-destructive text-xs">{settleForm.errors.sale_amount}</p>}
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label>Customer</Label>
                                                <Select value={settleForm.data.customer_id} onValueChange={(v) => settleForm.setData('customer_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                                                    <SelectContent>{customers.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.code} — {c.name}</SelectItem>)}</SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label>Receive into / debit</Label>
                                                <Select value={settleForm.data.sale_account_id} onValueChange={(v) => settleForm.setData('sale_account_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Defaults to Accounts Receivable" /></SelectTrigger>
                                                    <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}><span className="font-mono text-xs">{a.code}</span> {a.name}</SelectItem>)}</SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="sdate">Sale date</Label>
                                                <Input id="sdate" type="date" value={settleForm.data.settlement_date} onChange={(e) => settleForm.setData('settlement_date', e.target.value)} />
                                            </div>
                                            <div className="bg-muted/40 flex items-center justify-between rounded-lg px-3 py-2 text-sm">
                                                <span className="text-muted-foreground">Landed cost {money(trip.total_cost)} → projected {projectedProfit >= 0 ? 'profit' : 'loss'}</span>
                                                <span className={`font-mono font-semibold tabular-nums ${projectedProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>{money(projectedProfit)}</span>
                                            </div>
                                        </div>
                                        {errors.settlement && <div className="border-destructive/40 bg-destructive/10 text-destructive mb-2 rounded-md border px-3 py-2 text-sm">{errors.settlement}</div>}
                                        <DialogFooter><Button type="submit" disabled={settleForm.processing}>Record sale & profit</Button></DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                </div>

                {errors.posting && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.posting}</div>}
                {trip.status === 'draft' && <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-400">This trip is a draft. Review the journey below, then <strong>Post trip</strong> to capitalise the costs onto the goods.</div>}

                {/* P&L panel (settled) */}
                {trip.status === 'settled' && trip.profit !== null && (
                    <div className={`flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5 ${trip.profit >= 0 ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30' : 'border-rose-200 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/30'}`}>
                        <div className="flex items-center gap-3">
                            {trip.profit >= 0 ? <TrendingUp className="size-8 text-emerald-600 dark:text-emerald-400" /> : <TrendingDown className="size-8 text-rose-600 dark:text-rose-400" />}
                            <div>
                                <div className="text-muted-foreground text-xs uppercase tracking-wide">Batch {trip.profit >= 0 ? 'Profit' : 'Loss'}</div>
                                <div className={`font-mono text-3xl font-bold tabular-nums ${trip.profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>{money(trip.profit)}</div>
                            </div>
                        </div>
                        <div className="flex gap-6 text-sm">
                            <div><div className="text-muted-foreground text-xs uppercase">Sale</div><div className="font-mono tabular-nums">{money(trip.sale_amount ?? 0)}</div></div>
                            <div><div className="text-muted-foreground text-xs uppercase">Landed cost (COGS)</div><div className="font-mono tabular-nums">{money(trip.cogs_total ?? 0)}</div></div>
                            <div><div className="text-muted-foreground text-xs uppercase">Margin</div><div className="font-mono tabular-nums">{trip.sale_amount ? ((trip.profit / trip.sale_amount) * 100).toFixed(1) : '0.0'}%</div></div>
                        </div>
                    </div>
                )}

                {/* Cost summary */}
                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Goods cost</div><div className="mt-1 font-mono text-lg tabular-nums">{money(trip.goods_cost)}</div><div className="text-muted-foreground text-xs">{money(trip.quantity)} × {money(trip.goods_rate)}</div></div>
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Logistics</div><div className="mt-1 font-mono text-lg tabular-nums">{money(trip.logistics_cost)}</div><div className="text-muted-foreground text-xs">{trip.steps.length} legs</div></div>
                    <div className="rounded-xl border bg-muted/30 p-4"><div className="text-muted-foreground text-xs uppercase">Total landed cost</div><div className="mt-1 font-mono text-lg font-semibold tabular-nums">{money(trip.total_cost)}</div></div>
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Cost / unit</div><div className="mt-1 font-mono text-lg tabular-nums">{money(trip.cost_per_unit)}</div><div className="text-muted-foreground text-xs">{money(trip.quantity)} units · {trip.packages} bags</div></div>
                </div>

                {/* Journey */}
                <div>
                    <h2 className="text-muted-foreground mb-2 text-sm font-semibold uppercase tracking-wide">Journey — {trip.packages} bags · {money(trip.quantity)} units</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[760px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-3 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                    <th className="w-10">#</th>
                                    <th className="w-28">Type</th>
                                    <th>Description</th>
                                    <th className="w-28">Location</th>
                                    <th className="w-28">Truck</th>
                                    <th className="w-32 text-right">Basis / rate</th>
                                    <th className="w-28 text-right">Amount</th>
                                    <th className="w-24">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {/* Goods as the first row of the journey */}
                                <tr className="bg-muted/20 [&>td]:px-3 [&>td]:py-2">
                                    <td className="text-muted-foreground">1</td>
                                    <td className="capitalize">{trip.source}</td>
                                    <td>{trip.source === 'purchase' ? 'Purchase of goods' : 'Goods from production'}{trip.supplier ? ` · ${trip.supplier}` : ''}</td>
                                    <td>{trip.origin ?? '—'}</td>
                                    <td className="font-mono text-xs">{trip.vehicle_no ?? '—'}</td>
                                    <td className="text-right font-mono tabular-nums">{money(trip.goods_rate)}/unit</td>
                                    <td className="text-right font-mono tabular-nums">{money(trip.goods_cost)}</td>
                                    <td><Badge variant="secondary" className={statusStyle[trip.status === 'draft' ? 'draft' : 'posted']}>{trip.status === 'draft' ? 'pending' : 'done'}</Badge></td>
                                </tr>
                                {trip.steps.map((s) => (
                                    <tr key={s.sequence} className="[&>td]:px-3 [&>td]:py-2">
                                        <td className="text-muted-foreground">{s.sequence + 1}</td>
                                        <td className="capitalize">{s.type.replace('_', ' ')}</td>
                                        <td>{s.label ?? '—'}</td>
                                        <td>{s.location ?? '—'}</td>
                                        <td className="font-mono text-xs">{s.vehicle_no ?? '—'}</td>
                                        <td className="text-right font-mono tabular-nums text-xs">{money(s.rate)} <span className="text-muted-foreground">{basisLabel[s.basis] ?? s.basis}</span></td>
                                        <td className="text-right font-mono tabular-nums">{money(s.amount)}</td>
                                        <td><Badge variant="secondary" className={s.status === 'done' ? statusStyle.posted : statusStyle.draft}>{s.status === 'done' ? 'done' : 'pending'}</Badge></td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t font-medium">
                                <tr className="[&>td]:px-3 [&>td]:py-2.5">
                                    <td colSpan={6} className="text-right text-muted-foreground">Total landed cost</td>
                                    <td className="text-right font-mono tabular-nums">{money(trip.total_cost)}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                {trip.memo && <p className="text-muted-foreground text-sm">Memo: {trip.memo}</p>}
            </div>
        </>
    );
}
