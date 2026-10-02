import { Head, router, useForm } from '@inertiajs/react';
import { Boxes, Building2, Landmark, Plus, TrendingDown, Wallet, type LucideIcon } from 'lucide-react';
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
type Asset = { id: number; code: string; name: string; category: string | null; cost: number; accumulated_depreciation: number; book_value: number; status: string; acquisition_date: string };
type Summary = { count: number; cost: number; accumulated: number; book_value: number };
type Defaults = { asset_account_id: number | null; accum_account_id: number | null; depreciation_account_id: number | null };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

const statusStyle: Record<string, string> = {
    active: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    fully_depreciated: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    disposed: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
};

export default function FixedAssetsIndex({
    assets, assetAccounts, expenseAccounts, fundingAccounts, costCenters, summary, defaults,
}: {
    assets: Asset[]; assetAccounts: Option[]; expenseAccounts: Option[]; fundingAccounts: Option[]; costCenters: Option[]; summary: Summary; defaults: Defaults;
}) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);

    const tiles: { label: string; value: string; icon: LucideIcon; from: string; to: string }[] = [
        { label: 'Active Assets', value: String(summary.count), icon: Building2, from: '#6366f1', to: '#4338ca' },
        { label: 'Total Cost', value: money(summary.cost), icon: Wallet, from: '#0ea5e9', to: '#2563eb' },
        { label: 'Accumulated Dep.', value: money(summary.accumulated), icon: TrendingDown, from: '#f43f5e', to: '#be185d' },
        { label: 'Net Book Value', value: money(summary.book_value), icon: Landmark, from: '#10b981', to: '#0f766e' },
    ];

    return (
        <>
            <Head title="Fixed Assets" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#312e81', '#4f46e5')}>
                    <Building2 className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-indigo-200">Finance</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Fixed Assets</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">Capitalise assets, run straight-line depreciation, and dispose.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            {can('fixedasset.depreciate') && (
                                <Button variant="secondary" className="bg-white/15 text-white hover:bg-white/25" onClick={() => router.visit('/assets/depreciation')}><TrendingDown className="size-4" /> Depreciation</Button>
                            )}
                            {can('fixedasset.manage') && (
                                <Dialog open={open} onOpenChange={setOpen}>
                                    <DialogTrigger asChild><Button className="bg-white text-indigo-700 hover:bg-white/90"><Plus className="size-4" /> New asset</Button></DialogTrigger>
                                    <AssetDialog assetAccounts={assetAccounts} expenseAccounts={expenseAccounts} fundingAccounts={fundingAccounts} costCenters={costCenters} defaults={defaults} onDone={() => setOpen(false)} />
                                </Dialog>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {tiles.map((t) => (
                        <div key={t.label} className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(t.from, t.to)}>
                            <t.icon className="absolute -bottom-3 -right-3 size-20 opacity-20" />
                            <div className="relative">
                                <div className="text-xs font-medium uppercase tracking-wide text-white/80">{t.label}</div>
                                <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{t.value}</div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Code</th>
                                <th className="text-left">Name</th>
                                <th className="text-left">Category</th>
                                <th className="text-right">Cost</th>
                                <th className="text-right">Accum. Dep.</th>
                                <th className="text-right">Book Value</th>
                                <th className="text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {assets.length === 0 && <tr><td colSpan={7} className="text-muted-foreground px-4 py-10 text-center">No assets yet.</td></tr>}
                            {assets.map((a) => (
                                <tr key={a.id} onClick={() => router.visit(`/assets/${a.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-mono text-xs">{a.code}</td>
                                    <td className="font-medium">{a.name}</td>
                                    <td>{a.category ?? '—'}</td>
                                    <td className="text-right font-mono tabular-nums">{money(a.cost)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(a.accumulated_depreciation)}</td>
                                    <td className="text-right font-mono font-semibold tabular-nums">{money(a.book_value)}</td>
                                    <td><Badge variant="secondary" className={`capitalize ${statusStyle[a.status] ?? ''}`}>{a.status.replace('_', ' ')}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function AssetDialog({ assetAccounts, expenseAccounts, fundingAccounts, costCenters, defaults, onDone }: {
    assetAccounts: Option[]; expenseAccounts: Option[]; fundingAccounts: Option[]; costCenters: Option[]; defaults: Defaults; onDone: () => void;
}) {
    const form = useForm({
        code: '', name: '', category: '',
        asset_account_id: defaults.asset_account_id ? String(defaults.asset_account_id) : '',
        accum_account_id: defaults.accum_account_id ? String(defaults.accum_account_id) : '',
        depreciation_account_id: defaults.depreciation_account_id ? String(defaults.depreciation_account_id) : '',
        cost_center_id: '', funding_account_id: '',
        cost: '', salvage_value: '', useful_life_months: '60', acquisition_date: new Date().toISOString().slice(0, 10), memo: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/assets', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;
    const acctSelect = (key: keyof typeof form.data, label: string, opts: Option[]) => (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            <Select value={form.data[key] as string} onValueChange={(v) => form.setData(key, v)}>
                <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                <SelectContent>{opts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} — {a.name}</SelectItem>)}</SelectContent>
            </Select>
            {err(key)}
        </div>
    );

    return (
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader><DialogTitle>New fixed asset</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5"><Label>Code</Label><Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="FA-001" />{err('code')}</div>
                    <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Delivery Van" />{err('name')}</div>
                    <div className="grid gap-1.5"><Label>Category</Label><Input value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder="Vehicles" /></div>
                    <div className="grid gap-1.5"><Label>Cost center</Label>
                        <Select value={form.data.cost_center_id} onValueChange={(v) => form.setData('cost_center_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                            <SelectContent>{costCenters.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.code} — {c.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-1.5"><Label>Cost</Label><Input inputMode="decimal" value={form.data.cost} onChange={(e) => form.setData('cost', e.target.value)} className="text-right font-mono" />{err('cost')}</div>
                    <div className="grid gap-1.5"><Label>Salvage value</Label><Input inputMode="decimal" value={form.data.salvage_value} onChange={(e) => form.setData('salvage_value', e.target.value)} className="text-right font-mono" placeholder="0" />{err('salvage_value')}</div>
                    <div className="grid gap-1.5"><Label>Useful life (months)</Label><Input inputMode="numeric" value={form.data.useful_life_months} onChange={(e) => form.setData('useful_life_months', e.target.value)} className="text-right font-mono" />{err('useful_life_months')}</div>
                    <div className="grid gap-1.5"><Label>Acquisition date</Label><Input type="date" value={form.data.acquisition_date} onChange={(e) => form.setData('acquisition_date', e.target.value)} />{err('acquisition_date')}</div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    {acctSelect('asset_account_id', 'Asset account (PPE)', assetAccounts)}
                    {acctSelect('accum_account_id', 'Accumulated depreciation', assetAccounts)}
                    {acctSelect('depreciation_account_id', 'Depreciation expense', expenseAccounts)}
                    {acctSelect('funding_account_id', 'Funded from (cash/bank/payable)', fundingAccounts)}
                </div>
                <DialogFooter><Button type="submit" disabled={form.processing}>Capitalise asset</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}
