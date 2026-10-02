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

type Balance = { item: string; item_id: number; warehouse: string; quantity: number; value: number; rate: number };

export default function StockBalance({
    balances,
    totalValue,
    warehouses,
    filters,
}: {
    balances: Balance[];
    totalValue: number;
    warehouses: { id: number; code: string; name: string }[];
    filters: { warehouse_id: number | null };
}) {
    const setWarehouse = (v: string) => {
        router.get('/inventory/stock-balance', v === 'all' ? {} : { warehouse_id: v }, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Stock Balance" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Stock Balance</h1>
                        <p className="text-muted-foreground text-sm">Valued on-hand position</p>
                    </div>
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Warehouse</Label>
                        <Select value={filters.warehouse_id ? String(filters.warehouse_id) : 'all'} onValueChange={setWarehouse}>
                            <SelectTrigger className="w-52"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All warehouses</SelectItem>
                                {warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Item</th>
                                <th className="w-24 text-left">Warehouse</th>
                                <th className="w-32 text-right">Quantity</th>
                                <th className="w-32 text-right">Avg rate</th>
                                <th className="w-40 text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {balances.length === 0 && (
                                <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No stock on hand.</td></tr>
                            )}
                            {balances.map((b, i) => (
                                <tr
                                    key={i}
                                    onClick={() => router.visit(`/inventory/stock-ledger?item_id=${b.item_id}`)}
                                    className="hover:bg-muted/40 cursor-pointer"
                                >
                                    <td className="px-4 py-2.5">{b.item}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{b.warehouse}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(b.quantity)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(b.rate)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(b.value)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3">
                                <td colSpan={4} className="text-right">Total inventory value</td>
                                <td className="text-right font-mono tabular-nums">{money(totalValue)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">Click a row to open the item's stock ledger.</p>
            </div>
        </>
    );
}
