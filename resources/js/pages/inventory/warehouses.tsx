import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
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

type WarehouseRow = {
    id: number;
    code: string;
    name: string;
    type: string;
    cost_center: string | null;
    is_active: boolean;
};

const TYPES = ['warehouse', 'transit', 'production', 'consignment'];

export default function Warehouses({
    warehouses,
    costCenters,
}: {
    warehouses: WarehouseRow[];
    costCenters: { id: number; code: string; name: string }[];
}) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);

    const form = useForm({ code: '', name: '', type: 'warehouse', cost_center_id: '' as string });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/inventory/warehouses', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Warehouses" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Warehouses</h1>
                        <p className="text-muted-foreground text-sm">{warehouses.length} locations</p>
                    </div>
                    {can('inventory.item.manage') && (
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button><Plus className="size-4" /> New warehouse</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <form onSubmit={submit}>
                                    <DialogHeader>
                                        <DialogTitle>New warehouse</DialogTitle>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4">
                                        <div className="grid grid-cols-3 gap-3">
                                            <div className="grid gap-1.5">
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
                                            <div className="grid gap-1.5">
                                                <Label>Type</Label>
                                                <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        {TYPES.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label>Cost center</Label>
                                                <Select value={form.data.cost_center_id} onValueChange={(v) => form.setData('cost_center_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="—" /></SelectTrigger>
                                                    <SelectContent>
                                                        {costCenters.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button type="submit" disabled={form.processing}>Create warehouse</Button>
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
                                <th className="w-32">Type</th>
                                <th className="w-40">Cost center</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {warehouses.map((w) => (
                                <tr key={w.id} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs">{w.code}</td>
                                    <td className="px-4 py-2.5">{w.name}</td>
                                    <td className="px-4 py-2.5"><Badge variant="outline" className="capitalize">{w.type}</Badge></td>
                                    <td className="text-muted-foreground px-4 py-2.5">{w.cost_center ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-xs">{w.is_active ? <span className="text-emerald-600 dark:text-emerald-400">active</span> : <span className="text-muted-foreground">inactive</span>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
