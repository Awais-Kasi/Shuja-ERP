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

type Customer = {
    id: number;
    code: string;
    name: string;
    tax_registration_no: string | null;
    phone: string | null;
    payment_terms_days: number;
    is_active: boolean;
};

export default function Customers({ customers }: { customers: Customer[] }) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);
    const form = useForm({ code: '', name: '', tax_registration_no: '', phone: '', email: '', address: '', payment_terms_days: '0' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/sales/customers', { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };

    return (
        <>
            <Head title="Customers" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Customers</h1>
                        <p className="text-muted-foreground text-sm">{customers.length} customers</p>
                    </div>
                    {can('sales.customer.manage') && (
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild><Button><Plus className="size-4" /> New customer</Button></DialogTrigger>
                            <DialogContent>
                                <form onSubmit={submit}>
                                    <DialogHeader><DialogTitle>New customer</DialogTitle></DialogHeader>
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
                                                <Label htmlFor="ntn">Tax reg. no</Label>
                                                <Input id="ntn" value={form.data.tax_registration_no} onChange={(e) => form.setData('tax_registration_no', e.target.value)} />
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="phone">Phone</Label>
                                                <Input id="phone" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                                            </div>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="terms">Payment terms (days)</Label>
                                            <Input id="terms" type="number" value={form.data.payment_terms_days} onChange={(e) => form.setData('payment_terms_days', e.target.value)} className="w-32" />
                                        </div>
                                    </div>
                                    <DialogFooter><Button type="submit" disabled={form.processing}>Create customer</Button></DialogFooter>
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
                                <th className="w-40">Tax reg.</th>
                                <th className="w-32">Phone</th>
                                <th className="w-28 text-right">Terms</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {customers.map((c) => (
                                <tr key={c.id} className="hover:bg-muted/40">
                                    <td className="px-4 py-2.5 font-mono text-xs">{c.code}</td>
                                    <td className="px-4 py-2.5">{c.name}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">{c.tax_registration_no ?? '—'}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">{c.phone ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{c.payment_terms_days} d</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
