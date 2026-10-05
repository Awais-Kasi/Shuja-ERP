import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
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
import { usePermissions } from '@/hooks/use-permissions';

type UomRow = { id: number; code: string; name: string; is_active: boolean };

export default function Uoms({ uoms }: { uoms: UomRow[] }) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);

    const form = useForm({ code: '', name: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/inventory/uoms', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Units of Measure" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Units of Measure</h1>
                        <p className="text-muted-foreground text-sm">{uoms.length} units — the units your items are counted in.</p>
                    </div>
                    {can('inventory.item.manage') && (
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button><Plus className="size-4" /> New unit</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <form onSubmit={submit}>
                                    <DialogHeader>
                                        <DialogTitle>New unit</DialogTitle>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4">
                                        <div className="grid grid-cols-3 gap-3">
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="code">Code</Label>
                                                <Input id="code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="PCS" />
                                                {form.errors.code && <p className="text-destructive text-xs">{form.errors.code}</p>}
                                            </div>
                                            <div className="col-span-2 grid gap-1.5">
                                                <Label htmlFor="name">Name</Label>
                                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Pieces" />
                                                {form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}
                                            </div>
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button type="submit" disabled={form.processing}>Create unit</Button>
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
                                <th className="w-32">Code</th>
                                <th>Name</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {uoms.length === 0 && (
                                <tr><td colSpan={3} className="text-muted-foreground px-4 py-10 text-center">No units yet — click “New unit” to add one (e.g. PCS / Pieces, KG / Kilogram).</td></tr>
                            )}
                            {uoms.map((u) => (
                                <tr key={u.id} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs">{u.code}</td>
                                    <td className="px-4 py-2.5">{u.name}</td>
                                    <td className="px-4 py-2.5 text-xs">{u.is_active ? <span className="text-emerald-600 dark:text-emerald-400">active</span> : <span className="text-muted-foreground">inactive</span>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
