import { Head, router, useForm } from '@inertiajs/react';
import { Coins, Plus } from 'lucide-react';
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
import { money } from '@/lib/format';

type Worker = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    rate_per_kg: number | null;
    is_active: boolean;
    balance: number;
};

export default function WagesIndex({
    workers,
    totalOwed,
}: {
    workers: Worker[];
    totalOwed: number;
}) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);
    const form = useForm({
        code: '',
        name: '',
        phone: '',
        cnic: '',
        rate_per_kg: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/hr/wages', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Daily Wages" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <Coins className="size-6" /> Daily Wages (Dihari)
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Per-unit (e.g. per KG) workers. Each worker has a
                            wallet — total owed now:{' '}
                            <span className="font-mono font-medium">
                                {money(totalOwed)}
                            </span>
                            .
                        </p>
                    </div>
                    {can('hr.payroll.run') && (
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="size-4" /> New worker
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <form onSubmit={submit}>
                                    <DialogHeader>
                                        <DialogTitle>
                                            New daily-wage worker
                                        </DialogTitle>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4">
                                        <div className="grid grid-cols-3 gap-3">
                                            <div className="grid min-w-0 gap-1.5">
                                                <Label htmlFor="code">
                                                    Code
                                                </Label>
                                                <Input
                                                    id="code"
                                                    value={form.data.code}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'code',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. W-01"
                                                />
                                                {form.errors.code && (
                                                    <p className="text-destructive text-xs">
                                                        {form.errors.code}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="col-span-2 grid min-w-0 gap-1.5">
                                                <Label htmlFor="name">
                                                    Name
                                                </Label>
                                                <Input
                                                    id="name"
                                                    value={form.data.name}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                {form.errors.name && (
                                                    <p className="text-destructive text-xs">
                                                        {form.errors.name}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-3 gap-3">
                                            <div className="grid min-w-0 gap-1.5">
                                                <Label htmlFor="phone">
                                                    Phone
                                                </Label>
                                                <Input
                                                    id="phone"
                                                    value={form.data.phone}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'phone',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Optional"
                                                />
                                            </div>
                                            <div className="grid min-w-0 gap-1.5">
                                                <Label htmlFor="cnic">
                                                    CNIC
                                                </Label>
                                                <Input
                                                    id="cnic"
                                                    value={form.data.cnic}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'cnic',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Optional"
                                                />
                                            </div>
                                            <div className="grid min-w-0 gap-1.5">
                                                <Label htmlFor="rate">
                                                    Rate / KG
                                                </Label>
                                                <Input
                                                    id="rate"
                                                    inputMode="decimal"
                                                    className="text-right font-mono"
                                                    value={
                                                        form.data.rate_per_kg
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'rate_per_kg',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="0"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            Add worker
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-24">Code</th>
                                <th>Worker</th>
                                <th className="w-32">Phone</th>
                                <th className="w-28 text-right">Rate / KG</th>
                                <th className="w-40 text-right">
                                    Wallet (owed)
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {workers.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="text-muted-foreground px-4 py-10 text-center"
                                    >
                                        No workers yet. Click{' '}
                                        <strong>New worker</strong> to add your
                                        first daily-wage worker.
                                    </td>
                                </tr>
                            )}
                            {workers.map((w) => (
                                <tr
                                    key={w.id}
                                    onClick={() =>
                                        router.visit(`/hr/wages/${w.id}`)
                                    }
                                    className="hover:bg-muted/40 cursor-pointer"
                                >
                                    <td className="px-4 py-2.5 font-mono text-xs">
                                        {w.code}
                                    </td>
                                    <td className="px-4 py-2.5">{w.name}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">
                                        {w.phone ?? '—'}
                                    </td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">
                                        {w.rate_per_kg !== null
                                            ? money(w.rate_per_kg)
                                            : '—'}
                                    </td>
                                    <td
                                        className={`px-4 py-2.5 text-right font-mono tabular-nums ${w.balance > 0 ? 'text-amber-600 dark:text-amber-400' : ''}`}
                                    >
                                        {money(w.balance)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">
                    Click a worker to open their wallet — record per-KG work and
                    pay them daily, weekly or on demand.
                </p>
            </div>
        </>
    );
}
