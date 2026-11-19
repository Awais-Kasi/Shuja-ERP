import { Head, useForm } from '@inertiajs/react';
import { Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { ScreenHelp } from '@/components/screen-help';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money, rupees } from '@/lib/format';

type Supplier = { id: number; code: string; name: string };
type Warehouse = { id: number; code: string; name: string };
type ItemOpt = { id: number; code: string; name: string; rate: number };
type AccountOpt = { id: number; code: string; name: string; control_type: string };

type Props = {
    suppliers: Supplier[];
    warehouses: Warehouse[];
    items: ItemOpt[];
    accounts: AccountOpt[];
    today: string;
    baseCurrency: string;
};

type Line = { item_id: string; quantity: string; rate: string };

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

export default function QuickPurchase({ suppliers, warehouses, items, accounts, today, baseCurrency }: Props) {
    const form = useForm<{
        supplier_id: string;
        warehouse_id: string;
        purchase_date: string;
        supplier_invoice_no: string;
        memo: string;
        lines: Line[];
        amount_paid: string;
        account_id: string;
    }>({
        supplier_id: '',
        warehouse_id: warehouses[0] ? String(warehouses[0].id) : '',
        purchase_date: today,
        supplier_invoice_no: '',
        memo: '',
        lines: [{ item_id: '', quantity: '1', rate: '' }],
        amount_paid: '',
        account_id: accounts[0] ? String(accounts[0].id) : '',
    });

    const onItem = (idx: number, itemId: string) => {
        const item = items.find((it) => String(it.id) === itemId);
        form.setData(
            'lines',
            form.data.lines.map((l, i) =>
                i === idx ? { ...l, item_id: itemId, rate: l.rate || (item && item.rate ? String(item.rate) : l.rate) } : l,
            ),
        );
    };
    const setLine = (idx: number, key: keyof Line, val: string) => {
        form.setData('lines', form.data.lines.map((l, i) => (i === idx ? { ...l, [key]: val } : l)));
    };
    const addLine = () => form.setData('lines', [...form.data.lines, { item_id: '', quantity: '1', rate: '' }]);
    const removeLine = (idx: number) =>
        form.setData('lines', form.data.lines.length > 1 ? form.data.lines.filter((_, i) => i !== idx) : form.data.lines);

    const lineAmount = (l: Line) => (parseFloat(l.quantity || '0') * parseFloat(l.rate || '0')) || 0;
    const total = form.data.lines.reduce((s, l) => s + lineAmount(l), 0);
    const paid = parseFloat(form.data.amount_paid || '0') || 0;
    const balanceDue = Math.max(0, total - paid);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/purchase/quick');
    };

    const errors = Object.values(form.errors).filter(Boolean) as string[];

    return (
        <>
            <Head title="Quick Purchase" />
            <form onSubmit={submit} className="mx-auto flex h-full w-full max-w-4xl flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex items-center gap-2">
                    <ShoppingCart className="size-6 text-sky-500" />
                    <h1 className="text-2xl font-bold tracking-tight">Quick Purchase — Khareed</h1>
                </div>

                <ScreenHelp>
                    Aik hi screen par khareed: supplier chunein, maal daalein, aur agar paisa de diya to "Paisa diya" mein
                    likh dein. Maal stock mein aa jayega, supplier ke khata mein baqaya chadh jayega, aur payment bhi ek
                    saath lag jayega.
                </ScreenHelp>

                {errors.length > 0 && (
                    <div className="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-200">
                        <ul className="list-inside list-disc space-y-0.5">
                            {errors.map((msg, i) => (
                                <li key={i}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="bg-card grid gap-4 rounded-2xl border p-4 shadow-sm sm:grid-cols-4">
                    <div className="space-y-1.5 sm:col-span-2">
                        <Label>Supplier</Label>
                        <select className={selectClass} value={form.data.supplier_id} onChange={(e) => form.setData('supplier_id', e.target.value)}>
                            <option value="">— Supplier chunein —</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Godown</Label>
                        <select className={selectClass} value={form.data.warehouse_id} onChange={(e) => form.setData('warehouse_id', e.target.value)}>
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Tareekh</Label>
                        <Input type="date" value={form.data.purchase_date} onChange={(e) => form.setData('purchase_date', e.target.value)} />
                    </div>
                    <div className="space-y-1.5 sm:col-span-2">
                        <Label>Supplier Bill No. <span className="text-muted-foreground font-normal">(optional)</span></Label>
                        <Input value={form.data.supplier_invoice_no} onChange={(e) => form.setData('supplier_invoice_no', e.target.value)} placeholder="Supplier ki bill number" />
                    </div>
                </div>

                <div className="bg-card overflow-hidden rounded-2xl border shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs uppercase tracking-wide">
                                <tr>
                                    <th className="px-3 py-2.5 font-medium">Maal (Item)</th>
                                    <th className="w-24 px-3 py-2.5 font-medium">Qty</th>
                                    <th className="w-28 px-3 py-2.5 font-medium">Rate</th>
                                    <th className="w-32 px-3 py-2.5 text-right font-medium">Amount</th>
                                    <th className="w-10 px-3 py-2.5" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {form.data.lines.map((l, idx) => (
                                    <tr key={idx}>
                                        <td className="px-3 py-2 align-top">
                                            <select className={selectClass} value={l.item_id} onChange={(e) => onItem(idx, e.target.value)}>
                                                <option value="">— Item chunein —</option>
                                                {items.map((it) => (
                                                    <option key={it.id} value={it.id}>
                                                        {it.name} ({it.code})
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <Input type="number" min="0" step="any" value={l.quantity} onChange={(e) => setLine(idx, 'quantity', e.target.value)} />
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <Input type="number" min="0" step="any" value={l.rate} onChange={(e) => setLine(idx, 'rate', e.target.value)} />
                                        </td>
                                        <td className="px-3 py-2 text-right align-middle font-mono tabular-nums">{money(lineAmount(l))}</td>
                                        <td className="px-3 py-2 text-center align-middle">
                                            <button type="button" onClick={() => removeLine(idx)} className="text-muted-foreground hover:text-rose-600" title="Hatayein">
                                                <Trash2 className="size-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="border-t p-3">
                        <Button type="button" variant="outline" size="sm" onClick={addLine} className="gap-1.5">
                            <Plus className="size-4" /> Item add karein
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="bg-card space-y-3 rounded-2xl border p-4 shadow-sm">
                        <div className="text-sm font-semibold">Paisa diya (abhi)</div>
                        <div className="flex items-end gap-2">
                            <div className="flex-1 space-y-1.5">
                                <Label>Paid ({baseCurrency})</Label>
                                <Input type="number" min="0" step="any" placeholder="0" value={form.data.amount_paid} onChange={(e) => form.setData('amount_paid', e.target.value)} />
                            </div>
                            <Button type="button" variant="outline" onClick={() => form.setData('amount_paid', total ? total.toFixed(2) : '')}>
                                Poora
                            </Button>
                        </div>
                        {paid > 0 && (
                            <div className="space-y-1.5">
                                <Label>Kis mein se diya?</Label>
                                <select className={selectClass} value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)}>
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name} ({a.control_type === 'cash' ? 'Cash' : 'Bank'})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <p className="text-muted-foreground text-xs">
                            Khaali chhor dein agar poora udhaar hai — supplier ke khata mein baqaya chala jayega.
                        </p>
                    </div>

                    <div className="bg-card space-y-2 rounded-2xl border p-4 shadow-sm">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">Kul Khareed (Total)</span>
                            <span className="font-mono text-lg font-bold tabular-nums">{rupees(total)}</span>
                        </div>
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">Paisa diya</span>
                            <span className="font-mono tabular-nums text-sky-600 dark:text-sky-400">{money(paid)}</span>
                        </div>
                        <div className="flex items-center justify-between border-t pt-2 text-sm">
                            <span className="text-muted-foreground">Baqaya (Dena hai)</span>
                            <span className="font-mono text-base font-bold tabular-nums text-rose-600 dark:text-rose-400">{money(balanceDue)}</span>
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2 pb-6">
                    <Button type="submit" size="lg" disabled={form.processing} className="gap-2">
                        <ShoppingCart className="size-4" /> {form.processing ? 'Post ho raha hai…' : 'Khareed Post Karein'}
                    </Button>
                </div>
            </form>
        </>
    );
}
