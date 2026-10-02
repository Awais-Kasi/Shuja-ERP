import { Head, router } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { money } from '@/lib/format';

type Entry = {
    date: string;
    warehouse: string;
    entry_type: string;
    voucher: string | null;
    quantity: number;
    rate: number;
    value: number;
    balance_qty: number;
    balance_value: number;
};

type Filters = { item_id: number | null; warehouse_id: number | null };

export default function StockLedger({
    items,
    warehouses,
    filters,
    entries,
}: {
    items: { id: number; label: string }[];
    warehouses: { id: number; code: string; name: string }[];
    filters: Filters;
    entries: Entry[] | null;
}) {
    const reload = (patch: Partial<Record<string, string>>) => {
        const next: Record<string, string> = {};
        if (filters.item_id) next.item_id = String(filters.item_id);
        if (filters.warehouse_id) next.warehouse_id = String(filters.warehouse_id);
        Object.assign(next, patch);
        router.get('/inventory/stock-ledger', next, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Stock Ledger" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Stock Ledger</h1>
                    <p className="text-muted-foreground text-sm">Movement history with running balance</p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Item</Label>
                        <Select value={filters.item_id ? String(filters.item_id) : ''} onValueChange={(v) => reload({ item_id: v })}>
                            <SelectTrigger><SelectValue placeholder="Select item" /></SelectTrigger>
                            <SelectContent>
                                {items.map((i) => <SelectItem key={i.id} value={String(i.id)}>{i.label}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Warehouse</Label>
                        <Select value={filters.warehouse_id ? String(filters.warehouse_id) : 'all'} onValueChange={(v) => reload(v === 'all' ? { warehouse_id: '' } : { warehouse_id: v })}>
                            <SelectTrigger><SelectValue placeholder="All warehouses" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All warehouses</SelectItem>
                                {warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {!entries ? (
                    <div className="text-muted-foreground rounded-xl border py-16 text-center text-sm">Select an item to view its ledger.</div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                    <th className="w-28 text-left">Date</th>
                                    <th className="w-20 text-left">WH</th>
                                    <th className="w-28 text-left">Type</th>
                                    <th className="w-28 text-left">Voucher</th>
                                    <th className="w-24 text-right">Qty</th>
                                    <th className="w-24 text-right">Rate</th>
                                    <th className="w-28 text-right">Value</th>
                                    <th className="w-24 text-right">Bal qty</th>
                                    <th className="w-32 text-right">Bal value</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {entries.length === 0 && (
                                    <tr><td colSpan={9} className="text-muted-foreground px-4 py-10 text-center">No movements.</td></tr>
                                )}
                                {entries.map((e, i) => (
                                    <tr key={i} className="hover:bg-muted/40">
                                        <td className="px-4 py-2 tabular-nums">{e.date}</td>
                                        <td className="px-4 py-2 font-mono text-xs">{e.warehouse}</td>
                                        <td className="px-4 py-2 capitalize">{e.entry_type.replace('_', ' ')}</td>
                                        <td className="px-4 py-2 font-mono text-xs">{e.voucher ?? '—'}</td>
                                        <td className={`px-4 py-2 text-right font-mono tabular-nums ${e.quantity < 0 ? 'text-rose-600 dark:text-rose-400' : ''}`}>{money(e.quantity)}</td>
                                        <td className="px-4 py-2 text-right font-mono tabular-nums">{money(e.rate)}</td>
                                        <td className={`px-4 py-2 text-right font-mono tabular-nums ${e.value < 0 ? 'text-rose-600 dark:text-rose-400' : ''}`}>{money(e.value)}</td>
                                        <td className="px-4 py-2 text-right font-mono tabular-nums">{money(e.balance_qty)}</td>
                                        <td className="px-4 py-2 text-right font-mono tabular-nums">{money(e.balance_value)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
