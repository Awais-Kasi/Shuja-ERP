import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, HandCoins, PackagePlus } from 'lucide-react';
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

type Worker = { id: number; code: string; name: string; phone: string | null; cnic: string | null; rate_per_kg: number | null };
type Row = { date: string; number: string | null; type: string; detail: string; earned: number; paid: number; balance: number };
type Account = { id: number; code: string; name: string };

export default function WageWallet({
    worker, rows, totalEarned, totalPaid, balance, today, accounts,
}: {
    worker: Worker; rows: Row[]; totalEarned: number; totalPaid: number; balance: number; today: string; accounts: Account[];
}) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [workOpen, setWorkOpen] = useState(false);
    const [payOpen, setPayOpen] = useState(false);

    const workForm = useForm({ entry_date: today, quantity: '', rate: worker.rate_per_kg != null ? String(worker.rate_per_kg) : '', description: '' });
    const payForm = useForm({ payment_date: today, amount: '', account_id: accounts[0] ? String(accounts[0].id) : '', reference: '' });

    const workAmount = (parseFloat(workForm.data.quantity) || 0) * (parseFloat(workForm.data.rate) || 0);

    const submitWork = (e: FormEvent) => {
        e.preventDefault();
        workForm.post(`/hr/wages/${worker.id}/work`, { preserveScroll: true, onSuccess: () => { workForm.reset('quantity', 'description'); setWorkOpen(false); } });
    };
    const submitPay = (e: FormEvent) => {
        e.preventDefault();
        payForm.post(`/hr/wages/${worker.id}/pay`, { preserveScroll: true, onSuccess: () => { payForm.reset('amount', 'reference'); setPayOpen(false); } });
    };

    return (
        <>
            <Head title={`Wallet — ${worker.name}`} />
            <div className="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/hr/wages"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">{worker.name}</h1>
                            <p className="text-muted-foreground text-sm">
                                Daily-wage worker <span className="font-mono text-xs">{worker.code}</span>
                                {worker.phone ? ` · ${worker.phone}` : ''}{worker.rate_per_kg != null ? ` · ${money(worker.rate_per_kg)}/KG` : ''}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can('hr.payroll.run') && (
                            <Dialog open={workOpen} onOpenChange={setWorkOpen}>
                                <DialogTrigger asChild><Button variant="outline"><PackagePlus className="size-4" /> Record work</Button></DialogTrigger>
                                <DialogContent>
                                    <form onSubmit={submitWork}>
                                        <DialogHeader><DialogTitle>Record work — {worker.name}</DialogTitle></DialogHeader>
                                        <div className="grid gap-4 py-4">
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="grid gap-1.5"><Label htmlFor="wd">Date</Label><Input id="wd" type="date" value={workForm.data.entry_date} onChange={(e) => workForm.setData('entry_date', e.target.value)} /></div>
                                                <div className="grid gap-1.5"><Label htmlFor="desc">Work / note</Label><Input id="desc" value={workForm.data.description} onChange={(e) => workForm.setData('description', e.target.value)} placeholder="e.g. Bag stitching" /></div>
                                            </div>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="grid gap-1.5"><Label htmlFor="qty">Quantity (KG)</Label><Input id="qty" inputMode="decimal" className="text-right font-mono" value={workForm.data.quantity} onChange={(e) => workForm.setData('quantity', e.target.value)} placeholder="0" autoFocus />{workForm.errors.quantity && <p className="text-destructive text-xs">{workForm.errors.quantity}</p>}</div>
                                                <div className="grid gap-1.5"><Label htmlFor="rt">Rate / KG</Label><Input id="rt" inputMode="decimal" className="text-right font-mono" value={workForm.data.rate} onChange={(e) => workForm.setData('rate', e.target.value)} placeholder="0" /></div>
                                            </div>
                                            <div className="bg-muted/40 flex items-center justify-between rounded-lg px-3 py-2 text-sm"><span className="text-muted-foreground">Earned (added to wallet)</span><span className="font-mono font-semibold tabular-nums">{money(workAmount)}</span></div>
                                            {errors.work && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-3 py-2 text-sm">{errors.work}</div>}
                                        </div>
                                        <DialogFooter><Button type="submit" disabled={workForm.processing}>Add to wallet</Button></DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                        {can('hr.payroll.pay') && (
                            <Dialog open={payOpen} onOpenChange={setPayOpen}>
                                <DialogTrigger asChild><Button><HandCoins className="size-4" /> Pay</Button></DialogTrigger>
                                <DialogContent>
                                    <form onSubmit={submitPay}>
                                        <DialogHeader><DialogTitle>Pay {worker.name}</DialogTitle></DialogHeader>
                                        <div className="grid gap-4 py-4">
                                            <div className="bg-muted/40 flex items-center justify-between rounded-lg px-3 py-2 text-sm"><span className="text-muted-foreground">Currently owed</span><span className="font-mono font-semibold tabular-nums">{money(balance)}</span></div>
                                            <div className="grid grid-cols-2 gap-3">
                                                <div className="grid gap-1.5"><Label htmlFor="pd">Date</Label><Input id="pd" type="date" value={payForm.data.payment_date} onChange={(e) => payForm.setData('payment_date', e.target.value)} /></div>
                                                <div className="grid gap-1.5"><Label htmlFor="amt">Amount</Label><Input id="amt" inputMode="decimal" className="text-right font-mono" value={payForm.data.amount} onChange={(e) => payForm.setData('amount', e.target.value)} placeholder="0" autoFocus />{payForm.errors.amount && <p className="text-destructive text-xs">{payForm.errors.amount}</p>}</div>
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label>Pay from</Label>
                                                <Select value={payForm.data.account_id} onValueChange={(v) => payForm.setData('account_id', v)}>
                                                    <SelectTrigger className="w-full"><SelectValue placeholder="Cash / bank" /></SelectTrigger>
                                                    <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}><span className="font-mono text-xs">{a.code}</span> {a.name}</SelectItem>)}</SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid gap-1.5"><Label htmlFor="ref">Reference / note</Label><Input id="ref" value={payForm.data.reference} onChange={(e) => payForm.setData('reference', e.target.value)} placeholder="Optional" /></div>
                                            {errors.pay && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-3 py-2 text-sm">{errors.pay}</div>}
                                        </div>
                                        <DialogFooter><Button type="submit" disabled={payForm.processing}>Record payment</Button></DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Total earned</div><div className="mt-1 font-mono text-lg tabular-nums">{money(totalEarned)}</div></div>
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Total paid</div><div className="mt-1 font-mono text-lg tabular-nums">{money(totalPaid)}</div></div>
                    <div className="rounded-xl border bg-muted/30 p-4"><div className="text-muted-foreground text-xs uppercase">Wallet balance (owed)</div><div className={`mt-1 font-mono text-lg font-semibold tabular-nums ${balance > 0 ? 'text-amber-600 dark:text-amber-400' : ''}`}>{money(balance)}</div></div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="w-28 text-left">Date</th>
                                <th className="w-24 text-left">Voucher</th>
                                <th className="text-left">Detail</th>
                                <th className="w-32 text-right">Earned</th>
                                <th className="w-32 text-right">Paid</th>
                                <th className="w-36 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No activity yet. Use <strong>Record work</strong> to add per-KG work, then <strong>Pay</strong> to settle.</td></tr>}
                            {rows.map((r, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2">
                                    <td className="tabular-nums">{r.date}</td>
                                    <td className="font-mono text-xs">{r.number ?? '—'}</td>
                                    <td>{r.detail} {r.type === 'payment' && <Badge variant="secondary" className="ml-1 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">paid</Badge>}</td>
                                    <td className="text-right font-mono tabular-nums">{r.earned ? money(r.earned) : '—'}</td>
                                    <td className="text-right font-mono tabular-nums">{r.paid ? money(r.paid) : '—'}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.balance)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3">
                                <td colSpan={3} className="text-right">Totals</td>
                                <td className="text-right font-mono tabular-nums">{money(totalEarned)}</td>
                                <td className="text-right font-mono tabular-nums">{money(totalPaid)}</td>
                                <td className="text-right font-mono tabular-nums">{money(balance)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">A positive balance is money you still owe this worker. "Earned" and "Paid" both post to the accounts automatically.</p>
            </div>
        </>
    );
}
