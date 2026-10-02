import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, BookOpenText, PackageX } from 'lucide-react';
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
type Asset = {
    id: number; code: string; name: string; category: string | null; cost: number; salvage_value: number;
    useful_life_months: number; acquisition_date: string; accumulated_depreciation: number; book_value: number;
    monthly_depreciation: number; status: string; cost_center: string | null; journal_id: number | null; journal: string | null;
    disposed_at: string | null; disposal_journal_id: number | null;
};
type HistoryRow = { period: string; amount: number; journal_id: number | null };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

const statusStyle: Record<string, string> = {
    active: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    fully_depreciated: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    disposed: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
};

export default function ShowAsset({ asset, history, cashAccounts, today }: { asset: Asset; history: HistoryRow[]; cashAccounts: Option[]; today: string }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [disposeOpen, setDisposeOpen] = useState(false);

    const progress = asset.cost > 0 ? Math.min(100, (asset.accumulated_depreciation / (asset.cost - asset.salvage_value || 1)) * 100) : 0;

    return (
        <>
            <Head title={`Asset ${asset.code}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <Link href="/assets" className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm"><ArrowLeft className="size-4" /> All assets</Link>

                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#312e81', '#4f46e5')}>
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-widest text-indigo-200">
                                <span className="font-mono">{asset.code}</span>
                                <Badge variant="secondary" className={`capitalize ${statusStyle[asset.status] ?? ''}`}>{asset.status.replace('_', ' ')}</Badge>
                            </div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">{asset.name}</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">{asset.category ?? 'Uncategorised'} · acquired {asset.acquisition_date}{asset.cost_center ? ` · ${asset.cost_center}` : ''}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {asset.journal_id && <Button asChild variant="secondary" className="bg-white/15 text-white hover:bg-white/25"><Link href={`/accounting/journals/${asset.journal_id}`}><BookOpenText className="size-4" /> {asset.journal}</Link></Button>}
                            {asset.status !== 'disposed' && can('fixedasset.manage') && (
                                <Dialog open={disposeOpen} onOpenChange={setDisposeOpen}>
                                    <DialogTrigger asChild><Button className="bg-rose-600 text-white hover:bg-rose-700"><PackageX className="size-4" /> Dispose</Button></DialogTrigger>
                                    <DisposeDialog assetId={asset.id} bookValue={asset.book_value} cashAccounts={cashAccounts} today={today} onDone={() => setDisposeOpen(false)} />
                                </Dialog>
                            )}
                        </div>
                    </div>
                </div>

                {errors.disposal && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.disposal}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Cost" value={asset.cost} />
                    <Stat label="Accumulated Dep." value={asset.accumulated_depreciation} />
                    <Stat label="Net Book Value" value={asset.book_value} highlight />
                    <Stat label="Monthly Charge" value={asset.monthly_depreciation} />
                </div>

                <div className="bg-card rounded-2xl border p-5 shadow-sm">
                    <div className="mb-2 flex items-center justify-between text-sm">
                        <span className="font-semibold">Depreciation progress</span>
                        <span className="text-muted-foreground">{progress.toFixed(1)}% · salvage {money(asset.salvage_value)} · life {asset.useful_life_months} mo</span>
                    </div>
                    <div className="bg-muted h-2.5 overflow-hidden rounded-full">
                        <div className="h-full rounded-full transition-all duration-700" style={{ ...gradient('#6366f1', '#7c3aed'), width: `${progress}%` }} />
                    </div>
                </div>

                <div>
                    <h2 className="text-muted-foreground mb-2 text-sm font-semibold uppercase tracking-wide">Depreciation History</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                    <th className="text-left">Period</th>
                                    <th className="text-right">Charge</th>
                                    <th className="text-left">Journal</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {history.length === 0 && <tr><td colSpan={3} className="text-muted-foreground px-4 py-6 text-center">No depreciation posted yet.</td></tr>}
                                {history.map((h, i) => (
                                    <tr key={i} className="[&>td]:px-4 [&>td]:py-2">
                                        <td>{h.period}</td>
                                        <td className="text-right font-mono tabular-nums">{money(h.amount)}</td>
                                        <td className="text-xs">{h.journal_id && <Link href={`/accounting/journals/${h.journal_id}`} className="text-indigo-600 hover:underline dark:text-indigo-400">view</Link>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

function Stat({ label, value, highlight }: { label: string; value: number; highlight?: boolean }) {
    return (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-xs uppercase tracking-wide">{label}</div>
            <div className={`mt-1 font-mono text-xl font-semibold tabular-nums ${highlight ? 'text-indigo-600 dark:text-indigo-400' : ''}`}>{money(value)}</div>
        </div>
    );
}

function DisposeDialog({ assetId, bookValue, cashAccounts, today, onDone }: { assetId: number; bookValue: number; cashAccounts: Option[]; today: string; onDone: () => void }) {
    const form = useForm({ proceeds: '', cash_account_id: '', date: today });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/assets/${assetId}/dispose`, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    const gain = (parseFloat(form.data.proceeds) || 0) - bookValue;
    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>Dispose asset</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="bg-muted/40 flex items-center justify-between rounded-lg px-3 py-2 text-sm"><span className="text-muted-foreground">Net book value</span><span className="font-mono font-semibold tabular-nums">{money(bookValue)}</span></div>
                <div className="grid gap-1.5"><Label>Proceeds</Label><Input inputMode="decimal" value={form.data.proceeds} onChange={(e) => form.setData('proceeds', e.target.value)} className="text-right font-mono" placeholder="0" />{form.errors.proceeds && <p className="text-destructive text-xs">{form.errors.proceeds}</p>}</div>
                <div className="grid gap-1.5"><Label>Proceeds to</Label>
                    <Select value={form.data.cash_account_id} onValueChange={(v) => form.setData('cash_account_id', v)}>
                        <SelectTrigger><SelectValue placeholder="Cash / bank" /></SelectTrigger>
                        <SelectContent>{cashAccounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
                    </Select>
                    {form.errors.cash_account_id && <p className="text-destructive text-xs">{form.errors.cash_account_id}</p>}
                </div>
                <div className="grid gap-1.5"><Label>Date</Label><Input type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} /></div>
                {form.data.proceeds !== '' && (
                    <div className={`rounded-lg px-3 py-2 text-sm ${gain >= 0 ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-rose-500/10 text-rose-700 dark:text-rose-400'}`}>
                        {gain >= 0 ? 'Gain' : 'Loss'} on disposal: <span className="font-mono font-semibold">{money(Math.abs(gain))}</span>
                    </div>
                )}
                <DialogFooter><Button type="submit" disabled={form.processing} className="bg-rose-600 text-white hover:bg-rose-700">Confirm disposal</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}
