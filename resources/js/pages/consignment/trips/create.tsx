import { Head, useForm } from '@inertiajs/react';
import { Plus, Route, Trash2, Wand2 } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { money } from '@/lib/format';

type Option = { id: number; code: string; name: string };
type AccountOption = Option & { control_type: string | null; type: string };
type Step = {
    type: string; label: string; location: string; vehicle_no: string;
    basis: 'per_bag' | 'per_kg' | 'flat'; rate: string; credit_account_id: string;
};

const STEP_TYPES = ['loading', 'freight', 'customs', 'unloading', 'processing', 'storage', 'handling', 'other'];
const emptyStep = (): Step => ({ type: 'freight', label: '', location: '', vehicle_no: '', basis: 'flat', rate: '', credit_account_id: '' });

const computeAmount = (basis: string, rate: number, qty: number, packages: number) =>
    basis === 'per_bag' ? rate * packages : basis === 'per_kg' ? rate * qty : rate;

export default function CreateTrip({
    items, warehouses, suppliers, customers, accounts, today,
}: {
    items: Option[]; warehouses: Option[]; suppliers: Option[]; customers: Option[]; accounts: AccountOption[]; today: string;
}) {
    const form = useForm<{
        vehicle_no: string; source: 'purchase' | 'manufacture'; item_id: string; warehouse_id: string;
        supplier_id: string; customer_id: string; origin: string; destination: string;
        quantity: string; packages: string; goods_rate: string; goods_credit_account_id: string;
        sale_account_id: string; trip_date: string; memo: string; steps: Step[];
    }>({
        vehicle_no: '', source: 'purchase', item_id: '', warehouse_id: '', supplier_id: '', customer_id: '',
        origin: '', destination: '', quantity: '', packages: '', goods_rate: '', goods_credit_account_id: '',
        sale_account_id: '', trip_date: today, memo: '', steps: [emptyStep()],
    });

    const steps = form.data.steps;
    const setSteps = (n: Step[]) => form.setData('steps', n);
    const update = (i: number, f: keyof Step, v: string) => setSteps(steps.map((s, idx) => (idx === i ? { ...s, [f]: v } : s)));

    const qty = parseFloat(form.data.quantity) || 0;
    const pkg = parseInt(form.data.packages) || 0;
    const goodsRate = parseFloat(form.data.goods_rate) || 0;
    const goodsCost = qty * goodsRate;
    const logistics = steps.reduce((s, st) => s + computeAmount(st.basis, parseFloat(st.rate) || 0, qty, pkg), 0);
    const total = goodsCost + logistics;
    const perUnit = qty > 0 ? total / qty : 0;

    const err = form.errors as Record<string, string>;
    const submit = (e: FormEvent) => { e.preventDefault(); form.post('/consignment/trips'); };

    // One-click reproduction of the Turbat → Gwadar → Karachi demo journey.
    const loadExample = () => {
        form.setData((d) => ({
            ...d,
            source: 'purchase', vehicle_no: 'TKL-1234', origin: 'Turbat', destination: 'Karachi',
            quantity: '20000', packages: '1000', goods_rate: '450',
            steps: [
                { type: 'loading', label: 'Loading at Turbat', location: 'Turbat', vehicle_no: 'TKL-1234', basis: 'per_bag', rate: '10', credit_account_id: '' },
                { type: 'freight', label: 'Turbat → Gwadar freight', location: 'Turbat', vehicle_no: 'TKL-1234', basis: 'flat', rate: '90000', credit_account_id: '' },
                { type: 'customs', label: 'Customs (Turbat → Gwadar)', location: 'Turbat', vehicle_no: 'TKL-1234', basis: 'flat', rate: '150000', credit_account_id: '' },
                { type: 'unloading', label: 'Unloading at Gwadar', location: 'Gwadar', vehicle_no: 'TKL-1234', basis: 'per_bag', rate: '10', credit_account_id: '' },
                { type: 'processing', label: 'Wrapping / processing', location: 'Gwadar', vehicle_no: '', basis: 'per_kg', rate: '3.5', credit_account_id: '' },
                { type: 'loading', label: 'Loading at Gwadar', location: 'Gwadar', vehicle_no: 'GWK-5678', basis: 'per_bag', rate: '10', credit_account_id: '' },
                { type: 'freight', label: 'Gwadar → Karachi freight', location: 'Gwadar', vehicle_no: 'GWK-5678', basis: 'flat', rate: '170000', credit_account_id: '' },
                { type: 'handling', label: 'Driver allowance', location: 'Gwadar', vehicle_no: 'GWK-5678', basis: 'flat', rate: '10000', credit_account_id: '' },
                { type: 'unloading', label: 'Unloading at Karachi', location: 'Karachi', vehicle_no: 'GWK-5678', basis: 'per_bag', rate: '10', credit_account_id: '' },
                { type: 'storage', label: 'Storage at Karachi', location: 'Karachi', vehicle_no: '', basis: 'per_bag', rate: '20', credit_account_id: '' },
            ],
        }));
    };

    return (
        <>
            <Head title="New Consignment Trip" />
            <form onSubmit={submit} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight"><Route className="size-6" /> New Consignment Trip</h1>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" onClick={loadExample}><Wand2 className="size-4" /> Load example journey</Button>
                        <Button type="submit" disabled={form.processing}>Create trip</Button>
                    </div>
                </div>
                {err.posting && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{err.posting}</div>}

                {/* Trip header */}
                <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Goods come from</Label>
                        <Select value={form.data.source} onValueChange={(v) => form.setData('source', v as 'purchase' | 'manufacture')}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="purchase">Purchase (supplier)</SelectItem>
                                <SelectItem value="manufacture">Manufacture (own production)</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="truck">Truck / vehicle no</Label>
                        <Input id="truck" value={form.data.vehicle_no} onChange={(e) => form.setData('vehicle_no', e.target.value)} placeholder="e.g. TKL-1234" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="date">Trip date</Label>
                        <Input id="date" type="date" value={form.data.trip_date} onChange={(e) => form.setData('trip_date', e.target.value)} />
                        {err.trip_date && <p className="text-destructive text-xs">{err.trip_date}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Item (goods)</Label>
                        <Select value={form.data.item_id} onValueChange={(v) => form.setData('item_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select item" /></SelectTrigger>
                            <SelectContent>{items.map((it) => <SelectItem key={it.id} value={String(it.id)}><span className="font-mono text-xs">{it.code}</span> {it.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {err.item_id && <p className="text-destructive text-xs">{err.item_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Warehouse (destination)</Label>
                        <Select value={form.data.warehouse_id} onValueChange={(v) => form.setData('warehouse_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Where goods rest" /></SelectTrigger>
                            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                        {err.warehouse_id && <p className="text-destructive text-xs">{err.warehouse_id}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="origin">Origin</Label>
                        <Input id="origin" value={form.data.origin} onChange={(e) => form.setData('origin', e.target.value)} placeholder="e.g. Turbat" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="dest">Destination</Label>
                        <Input id="dest" value={form.data.destination} onChange={(e) => form.setData('destination', e.target.value)} placeholder="e.g. Karachi" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="qty">Quantity (in item unit, e.g. KG)</Label>
                        <Input id="qty" inputMode="decimal" className="text-right font-mono" value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} placeholder="0" />
                        {err.quantity && <p className="text-destructive text-xs">{err.quantity}</p>}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="pkg">Packages (bags)</Label>
                        <Input id="pkg" inputMode="numeric" className="text-right font-mono" value={form.data.packages} onChange={(e) => form.setData('packages', e.target.value)} placeholder="0" />
                    </div>
                </div>

                {/* Goods cost */}
                <div className="rounded-xl border bg-muted/20 p-4">
                    <h2 className="text-muted-foreground mb-3 text-sm font-semibold uppercase tracking-wide">
                        {form.data.source === 'purchase' ? 'Step 1 — Purchase the goods' : 'Step 1 — Manufactured goods (already in stock)'}
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="rate">{form.data.source === 'purchase' ? 'Purchase rate / unit' : 'Production cost / unit'}</Label>
                            <Input id="rate" inputMode="decimal" className="text-right font-mono" value={form.data.goods_rate} onChange={(e) => form.setData('goods_rate', e.target.value)} placeholder="0" />
                            {err.goods_rate && <p className="text-destructive text-xs">{err.goods_rate}</p>}
                        </div>
                        {form.data.source === 'purchase' && (
                            <>
                                <div className="grid gap-1.5">
                                    <Label>Supplier</Label>
                                    <Select value={form.data.supplier_id} onValueChange={(v) => form.setData('supplier_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                                        <SelectContent>{suppliers.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.code} — {s.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Pay from / credit</Label>
                                    <Select value={form.data.goods_credit_account_id} onValueChange={(v) => form.setData('goods_credit_account_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Defaults to Accounts Payable" /></SelectTrigger>
                                        <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}><span className="font-mono text-xs">{a.code}</span> {a.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                </div>
                            </>
                        )}
                    </div>
                    <p className="text-muted-foreground mt-3 text-xs">
                        {form.data.source === 'purchase'
                            ? 'Posting books Dr Inventory / Cr Payable (or the account you pick) and receives the goods into stock.'
                            : 'Manufactured goods are already in stock from their work order, so nothing is received here — the rate is used only to show the batch cost base. The journey steps below are identical to a purchased batch.'}
                    </p>
                </div>

                {/* Journey steps */}
                <div>
                    <h2 className="text-muted-foreground mb-2 text-sm font-semibold uppercase tracking-wide">Journey — each leg's cost is added onto the goods</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[920px] text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-2.5 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                    <th className="w-32">Type</th>
                                    <th>Description</th>
                                    <th className="w-28">Location</th>
                                    <th className="w-28">Truck</th>
                                    <th className="w-28">Basis</th>
                                    <th className="w-24 text-right">Rate</th>
                                    <th className="w-44">Pay from / credit</th>
                                    <th className="w-28 text-right">Amount</th>
                                    <th className="w-8"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {steps.map((s, i) => (
                                    <tr key={i} className="[&>td]:px-2.5 [&>td]:py-1.5 align-top">
                                        <td>
                                            <Select value={s.type} onValueChange={(v) => update(i, 'type', v)}>
                                                <SelectTrigger className="w-full capitalize"><SelectValue /></SelectTrigger>
                                                <SelectContent>{STEP_TYPES.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </td>
                                        <td><Input value={s.label} onChange={(e) => update(i, 'label', e.target.value)} placeholder="e.g. Turbat → Gwadar freight" /></td>
                                        <td><Input value={s.location} onChange={(e) => update(i, 'location', e.target.value)} placeholder="—" /></td>
                                        <td><Input value={s.vehicle_no} onChange={(e) => update(i, 'vehicle_no', e.target.value)} className="font-mono" placeholder="truck" /></td>
                                        <td>
                                            <Select value={s.basis} onValueChange={(v) => update(i, 'basis', v)}>
                                                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="per_bag">Per bag</SelectItem>
                                                    <SelectItem value="per_kg">Per kg/unit</SelectItem>
                                                    <SelectItem value="flat">Flat</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </td>
                                        <td><Input inputMode="decimal" className="text-right font-mono" value={s.rate} onChange={(e) => update(i, 'rate', e.target.value)} placeholder="0" /></td>
                                        <td>
                                            <Select value={s.credit_account_id} onValueChange={(v) => update(i, 'credit_account_id', v)}>
                                                <SelectTrigger className="w-full"><SelectValue placeholder="Cash (default)" /></SelectTrigger>
                                                <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}><span className="font-mono text-xs">{a.code}</span> {a.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </td>
                                        <td className="text-right font-mono tabular-nums">{money(computeAmount(s.basis, parseFloat(s.rate) || 0, qty, pkg))}</td>
                                        <td className="text-center">{steps.length > 1 && <Button type="button" variant="ghost" size="icon" onClick={() => setSteps(steps.filter((_, idx) => idx !== i))}><Trash2 className="text-muted-foreground size-4" /></Button>}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t"><tr><td colSpan={9} className="px-2.5 py-2.5"><Button type="button" variant="outline" size="sm" onClick={() => setSteps([...steps, emptyStep()])}><Plus className="size-4" /> Add leg</Button></td></tr></tfoot>
                        </table>
                    </div>
                </div>

                {/* Totals */}
                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Goods cost</div><div className="mt-1 font-mono text-lg tabular-nums">{money(goodsCost)}</div></div>
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Logistics (all legs)</div><div className="mt-1 font-mono text-lg tabular-nums">{money(logistics)}</div></div>
                    <div className="rounded-xl border bg-muted/30 p-4"><div className="text-muted-foreground text-xs uppercase">Total landed cost</div><div className="mt-1 font-mono text-lg font-semibold tabular-nums">{money(total)}</div></div>
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Landed cost / unit</div><div className="mt-1 font-mono text-lg tabular-nums">{money(perUnit)}</div></div>
                </div>
                <p className="text-muted-foreground text-xs">Create the trip as a draft, then post it to capitalise these costs onto the goods. Selling (settlement) then shows the batch profit or loss.</p>
            </form>
        </>
    );
}
