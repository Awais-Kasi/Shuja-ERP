import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2, Zap } from 'lucide-react';
import { ScreenHelp } from '@/components/screen-help';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money, rupees } from '@/lib/format';

type Customer = { id: number; code: string; name: string };
type Warehouse = { id: number; code: string; name: string };
type ItemOpt = { id: number; code: string; name: string; rate: number };
type AccountOpt = { id: number; code: string; name: string; control_type: string };

type Props = {
    customers: Customer[];
    warehouses: Warehouse[];
    items: ItemOpt[];
    accounts: AccountOpt[];
    today: string;
    baseCurrency: string;
};

type Line = { item_id: string; quantity: string; rate: string };

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 py-1 text-sm shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

export default function QuickSale({ customers, warehouses, items, accounts, today, baseCurrency }: Props) {
    const form = useForm<{
        customer_id: string;
        warehouse_id: string;
        invoice_date: string;
        memo: string;
        lines: Line[];
        amount_received: string;
        account_id: string;
    }>({
        customer_id: '',
        warehouse_id: warehouses[0] ? String(warehouses[0].id) : '',
        invoice_date: today,
        memo: '',
        lines: [{ item_id: '', quantity: '1', rate: '' }],
        amount_received: '',
        account_id: accounts[0] ? String(accounts[0].id) : '',
    });

    const setLine = (idx: number, key: keyof Line, val: string) => {
        form.setData(
            'lines',
            form.data.lines.map((l, i) => (i === idx ? { ...l, [key]: val } : l)),
        );
    };
    const addLine = () => form.setData('lines', [...form.data.lines, { item_id: '', quantity: '1', rate: '' }]);
    const removeLine = (idx: number) =>
        form.setData('lines', form.data.lines.length > 1 ? form.data.lines.filter((_, i) => i !== idx) : form.data.lines);

    const lineAmount = (l: Line) => (parseFloat(l.quantity || '0') * parseFloat(l.rate || '0')) || 0;
    const total = form.data.lines.reduce((s, l) => s + lineAmount(l), 0);
    const received = parseFloat(form.data.amount_received || '0') || 0;
    const balanceDue = Math.max(0, total - received);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/sales/quick');
    };

    const errors = Object.values(form.errors).filter(Boolean) as string[];

    return (
        <>
            <Head title="Quick Sale" />
            <form onSubmit={submit} className="mx-auto flex h-full w-full max-w-4xl flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex items-center gap-2">
                    <Zap className="size-6 text-amber-500" />
                    <h1 className="text-2xl font-bold tracking-tight">Quick Sale — Cash Memo</h1>
                </div>

                <ScreenHelp>
                    Aik hi screen par bikri: customer chunein, maal daalein, aur agar paisa mil gaya to "Paisa mila" mein
                    likh dein — sab kuch aik saath post ho jayega. Cash customer ke liye aik baar{' '}
                    <b>"Cash Sale"</b> naam ka customer bana lein.
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

                {/* Header fields */}
                <div className="bg-card grid gap-4 rounded-2xl border p-4 shadow-sm sm:grid-cols-3">
                    <div className="space-y-1.5 sm:col-span-1">
                        <Label>Customer</Label>
                        <select
                            className={selectClass}
                            value={form.data.customer_id}
                            onChange={(e) => form.setData('customer_id', e.target.value)}
                        >
                            <option value="">— Customer chunein —</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Godown</Label>
                        <select
                            className={selectClass}
                            value={form.data.warehouse_id}
                            onChange={(e) => form.setData('warehouse_id', e.target.value)}
                        >
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Tareekh</Label>
                        <Input
                            type="date"
                            value={form.data.invoice_date}
                            onChange={(e) => form.setData('invoice_date', e.target.value)}
                        />
                    </div>
                </div>

                {/* Lines */}
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
                                            <select
                                                className={selectClass}
                                                value={l.item_id}
                                                onChange={(e) => setLine(idx, 'item_id', e.target.value)}
                                            >
                                                <option value="">— Item chunein —</option>
                                                {items.map((it) => (
                                                    <option key={it.id} value={it.id}>
                                                        {it.name} ({it.code})
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <Input
                                                type="number"
                                                min="0"
                                                step="any"
                                                value={l.quantity}
                                                onChange={(e) => setLine(idx, 'quantity', e.target.value)}
                                            />
                                        </td>
                                        <td className="px-3 py-2 align-top">
                                            <Input
                                                type="number"
                                                min="0"
                                                step="any"
                                                value={l.rate}
                                                onChange={(e) => setLine(idx, 'rate', e.target.value)}
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-right align-middle font-mono tabular-nums">
                                            {money(lineAmount(l))}
                                        </td>
                                        <td className="px-3 py-2 text-center align-middle">
                                            <button
                                                type="button"
                                                onClick={() => removeLine(idx)}
                                                className="text-muted-foreground hover:text-rose-600"
                                                title="Hatayein"
                                            >
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

                {/* Totals + payment */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="bg-card space-y-3 rounded-2xl border p-4 shadow-sm">
                        <div className="text-sm font-semibold">Paisa mila (abhi)</div>
                        <div className="flex items-end gap-2">
                            <div className="flex-1 space-y-1.5">
                                <Label>Received ({baseCurrency})</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder="0"
                                    value={form.data.amount_received}
                                    onChange={(e) => form.setData('amount_received', e.target.value)}
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => form.setData('amount_received', total ? total.toFixed(2) : '')}
                            >
                                Poora
                            </Button>
                        </div>
                        {received > 0 && (
                            <div className="space-y-1.5">
                                <Label>Kis mein aaya?</Label>
                                <select
                                    className={selectClass}
                                    value={form.data.account_id}
                                    onChange={(e) => form.setData('account_id', e.target.value)}
                                >
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name} ({a.control_type === 'cash' ? 'Cash' : 'Bank'})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <p className="text-muted-foreground text-xs">
                            Khaali chhor dein agar poora udhaar hai — customer ke khata mein baqaya chala jayega.
                        </p>
                    </div>

                    <div className="bg-card space-y-2 rounded-2xl border p-4 shadow-sm">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">Kul Bikri (Total)</span>
                            <span className="font-mono text-lg font-bold tabular-nums">{rupees(total)}</span>
                        </div>
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">Paisa mila</span>
                            <span className="font-mono tabular-nums text-emerald-600 dark:text-emerald-400">{money(received)}</span>
                        </div>
                        <div className="flex items-center justify-between border-t pt-2 text-sm">
                            <span className="text-muted-foreground">Baqaya (Udhaar)</span>
                            <span className="font-mono text-base font-bold tabular-nums text-rose-600 dark:text-rose-400">
                                {money(balanceDue)}
                            </span>
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-end gap-2 pb-6">
                    <Button type="submit" size="lg" disabled={form.processing} className="gap-2">
                        <Zap className="size-4" /> {form.processing ? 'Post ho raha hai…' : 'Bikri Post Karein'}
                    </Button>
                </div>
            </form>
        </>
    );
}
