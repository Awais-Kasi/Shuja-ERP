import { Head, router } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { money } from '@/lib/format';

type Row = { code: string; name: string; warehouse: string; quantity: number; rate: number; value: number };
type Report = { rows: Row[]; total: number };
type Warehouse = { id: number; code: string; name: string };

export default function InventoryValuation({ report, warehouses, filters }: { report: Report; warehouses: Warehouse[]; filters: { warehouse_id: number | null } }) {
    const setWarehouse = (v: string) => router.get('/reports/inventory-valuation', v === 'all' ? {} : { warehouse_id: v }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Stock Valuation" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Stock Valuation</h1>
                        <p className="text-muted-foreground text-sm">Current inventory holdings at cost</p>
                    </div>
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Warehouse</Label>
                        <Select value={filters.warehouse_id ? String(filters.warehouse_id) : 'all'} onValueChange={setWarehouse}>
                            <SelectTrigger className="w-56"><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value="all">All warehouses</SelectItem>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.code} — {w.name}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[680px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Code</th><th className="text-left">Item</th><th className="text-left">Warehouse</th>
                                <th className="text-right">Quantity</th><th className="text-right">Avg rate</th><th className="text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {report.rows.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No stock on hand.</td></tr>}
                            {report.rows.map((r, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-mono text-xs">{r.code}</td>
                                    <td className="font-medium">{r.name}</td>
                                    <td className="text-muted-foreground text-xs">{r.warehouse}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.quantity)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.rate)}</td>
                                    <td className="text-right font-mono font-semibold tabular-nums">{money(r.value)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold"><tr className="[&>td]:px-4 [&>td]:py-3"><td colSpan={5} className="text-right">Total inventory value</td><td className="text-right font-mono tabular-nums">{money(report.total)}</td></tr></tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
