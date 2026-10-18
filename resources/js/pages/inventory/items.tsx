import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type ItemRow = {
    id: number;
    code: string;
    name: string;
    type: string;
    uom: string | null;
    valuation: string;
    account: string | null;
    qty: number;
    value: number;
    is_active: boolean;
};

type Option = { id: number; label?: string; code?: string; name?: string };
type AccountOption = { id: number; code: string; label: string; type: string };

const TYPES = ['stock', 'raw_material', 'finished_good', 'consumable', 'service'];

export default function Items({
    items,
    uoms,
    accounts,
    defaultValuation,
}: {
    items: ItemRow[];
    uoms: Option[];
    accounts: AccountOption[];
    defaultValuation: string;
}) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);
    const [advanced, setAdvanced] = useState(false);

    const assetAccounts = accounts.filter((a) => a.type === 'asset');
    const incomeAccounts = accounts.filter((a) => a.type === 'income');
    const expenseAccounts = accounts.filter((a) => a.type === 'expense');

    // Pick the right ledger accounts automatically from the item type, so users
    // never have to understand accounting to add an item.
    const idByCode = (code: string) => {
        const a = accounts.find((x) => x.code === code);
        return a ? String(a.id) : '';
    };
    const accountDefaults = (type: string) => ({
        inventory_account_id: type === 'service' ? '' : idByCode(type === 'raw_material' ? '1121' : '1123'),
        income_account_id: type === 'raw_material' ? '' : idByCode('4100'),
        cogs_account_id: type === 'raw_material' ? '' : idByCode('5100'),
    });

    const form = useForm({
        code: '',
        name: '',
        type: 'stock',
        uom_id: '' as string,
        valuation_method: '' as string,
        ...accountDefaults('stock'),
    });
    // Sales accounts only matter for items you sell (finished goods, stock, etc.).
    const sellable = form.data.type !== 'raw_material';
    const onType = (v: string) => form.setData((d) => ({ ...d, type: v, ...accountDefaults(v) }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/inventory/items', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Items" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Items</h1>
                        <p className="text-muted-foreground text-sm">{items.length} items</p>
                    </div>
                    {can('inventory.item.manage') && (
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button><Plus className="size-4" /> New item</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <form onSubmit={submit}>
                                    <DialogHeader>
                                        <DialogTitle>New item</DialogTitle>
                                        <DialogDescription>Add a stock or service item.</DialogDescription>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4">
                                        <div className="grid grid-cols-3 gap-3">
                                            <div className="grid min-w-0 gap-1.5">
                                                <Label htmlFor="code">Code</Label>
                                                <Input id="code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                                                {form.errors.code && <p className="text-destructive text-xs">{form.errors.code}</p>}
                                            </div>
                                            <div className="col-span-2 grid gap-1.5">
                                                <Label htmlFor="name">Name</Label>
                                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                                {form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="grid min-w-0 gap-1.5">
                                                <Label>Type</Label>
                                                <Select value={form.data.type} onValueChange={onType}>
                                                    <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        {TYPES.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid min-w-0 gap-1.5">
                                                <Label>Unit</Label>
                                                <Select value={form.data.uom_id} onValueChange={(v) => form.setData('uom_id', v)}>
                                                    <SelectTrigger className="w-full"><SelectValue placeholder="e.g. KG" /></SelectTrigger>
                                                    <SelectContent>
                                                        {uoms.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.code} — {u.name}</SelectItem>)}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>

                                        <p className="text-muted-foreground bg-muted/40 rounded-md px-3 py-2 text-xs">
                                            ✓ The accounting (inventory, income &amp; cost accounts) is filled in automatically from the Type. Open <b>Advanced</b> only to change where it posts.
                                        </p>

                                        <button type="button" onClick={() => setAdvanced((a) => !a)} className="text-muted-foreground hover:text-foreground flex w-fit items-center gap-1 text-xs font-medium">
                                            {advanced ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />} Advanced options
                                        </button>

                                        {advanced && (
                                            <div className="bg-muted/20 grid gap-4 rounded-lg border p-3">
                                                <div className="grid grid-cols-2 gap-3">
                                                    <div className="grid min-w-0 gap-1.5">
                                                        <Label>Valuation</Label>
                                                        <Select value={form.data.valuation_method} onValueChange={(v) => form.setData('valuation_method', v === 'default' ? '' : v)}>
                                                            <SelectTrigger className="w-full"><SelectValue placeholder="Company default" /></SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="default">Company default</SelectItem>
                                                                <SelectItem value="weighted_average">Weighted Average</SelectItem>
                                                                <SelectItem value="fifo">FIFO</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div className="grid min-w-0 gap-1.5">
                                                        <Label>Inventory account</Label>
                                                        <Select value={form.data.inventory_account_id} onValueChange={(v) => form.setData('inventory_account_id', v)}>
                                                            <SelectTrigger className="w-full"><SelectValue placeholder="—" /></SelectTrigger>
                                                            <SelectContent>
                                                                {assetAccounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.label}</SelectItem>)}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                </div>
                                                {sellable && (
                                                    <div className="grid grid-cols-2 gap-3">
                                                        <div className="grid min-w-0 gap-1.5">
                                                            <Label>Income account <span className="text-muted-foreground font-normal">(sales)</span></Label>
                                                            <Select value={form.data.income_account_id} onValueChange={(v) => form.setData('income_account_id', v)}>
                                                                <SelectTrigger className="w-full"><SelectValue placeholder="—" /></SelectTrigger>
                                                                <SelectContent>
                                                                    {incomeAccounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.label}</SelectItem>)}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <div className="grid min-w-0 gap-1.5">
                                                            <Label>COGS account <span className="text-muted-foreground font-normal">(sales)</span></Label>
                                                            <Select value={form.data.cogs_account_id} onValueChange={(v) => form.setData('cogs_account_id', v)}>
                                                                <SelectTrigger className="w-full"><SelectValue placeholder="—" /></SelectTrigger>
                                                                <SelectContent>
                                                                    {expenseAccounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.label}</SelectItem>)}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    <DialogFooter>
                                        <Button type="submit" disabled={form.processing}>Create item</Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-28">Code</th>
                                <th>Name</th>
                                <th className="w-28">Type</th>
                                <th className="w-16">UOM</th>
                                <th className="w-36">Valuation</th>
                                <th className="w-28 text-right">On hand</th>
                                <th className="w-36 text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {items.map((i) => (
                                <tr key={i.id} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs">{i.code}</td>
                                    <td className="px-4 py-2.5">{i.name}</td>
                                    <td className="text-muted-foreground px-4 py-2.5 capitalize">{i.type.replace('_', ' ')}</td>
                                    <td className="px-4 py-2.5">{i.uom ?? '—'}</td>
                                    <td className="px-4 py-2.5">{i.valuation}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{i.qty ? money(i.qty) : '—'}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{i.value ? money(i.value) : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
