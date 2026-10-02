import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';

type AccountRow = {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    type: string;
    is_group: boolean;
    control_type: string | null;
    is_active: boolean;
};

const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

const typeColor: Record<string, string> = {
    asset: 'text-sky-600 dark:text-sky-400',
    liability: 'text-amber-600 dark:text-amber-400',
    equity: 'text-violet-600 dark:text-violet-400',
    income: 'text-emerald-600 dark:text-emerald-400',
    expense: 'text-rose-600 dark:text-rose-400',
};

export default function ChartOfAccounts({
    accounts,
    groups,
}: {
    accounts: AccountRow[];
    groups: AccountRow[];
}) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);

    const depthOf = useMemo(() => {
        const byId = new Map(accounts.map((a) => [a.id, a]));
        return (a: AccountRow) => {
            let depth = 0;
            let cur = a.parent_id;
            while (cur != null && depth < 10) {
                depth++;
                cur = byId.get(cur)?.parent_id ?? null;
            }
            return depth;
        };
    }, [accounts]);

    const form = useForm({
        code: '',
        name: '',
        type: 'asset',
        parent_id: '' as string,
        control_type: '',
        is_group: false as boolean,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/accounting/accounts', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Chart of Accounts" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Chart of Accounts</h1>
                        <p className="text-muted-foreground text-sm">{accounts.length} accounts</p>
                    </div>
                    {can('accounting.account.manage') && (
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="size-4" /> New account
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <form onSubmit={submit}>
                                    <DialogHeader>
                                        <DialogTitle>New account</DialogTitle>
                                        <DialogDescription>Add a ledger account to the chart.</DialogDescription>
                                    </DialogHeader>
                                    <div className="grid gap-4 py-4">
                                        <div className="grid grid-cols-3 gap-3">
                                            <div className="col-span-1 grid gap-1.5">
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
                                                        {TYPES.map((t) => (
                                                            <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label>Parent (group)</Label>
                                                <Select value={form.data.parent_id} onValueChange={(v) => form.setData('parent_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                                    <SelectContent>
                                                        {groups.map((g) => (
                                                            <SelectItem key={g.id} value={String(g.id)}>{g.code} — {g.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                        <label className="flex items-center gap-2 text-sm">
                                            <Checkbox checked={form.data.is_group} onCheckedChange={(v) => form.setData('is_group', Boolean(v))} />
                                            This is a group / header account (not postable)
                                        </label>
                                    </div>
                                    <DialogFooter>
                                        <Button type="submit" disabled={form.processing}>Create account</Button>
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
                                <th>Account</th>
                                <th className="w-32">Type</th>
                                <th className="w-32">Control</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {accounts.map((a) => (
                                <tr key={a.id} className={a.is_group ? 'bg-muted/20 font-semibold' : ''}>
                                    <td className="px-4 py-2 font-mono text-xs tabular-nums">{a.code}</td>
                                    <td className="px-4 py-2">
                                        <span style={{ paddingLeft: `${depthOf(a) * 16}px` }}>{a.name}</span>
                                    </td>
                                    <td className={`px-4 py-2 capitalize ${typeColor[a.type] ?? ''}`}>{a.type}</td>
                                    <td className="px-4 py-2">
                                        {a.control_type ? <Badge variant="outline" className="font-mono text-[10px] uppercase">{a.control_type}</Badge> : <span className="text-muted-foreground">—</span>}
                                    </td>
                                    <td className="px-4 py-2">
                                        {a.is_group ? (
                                            <span className="text-muted-foreground text-xs">group</span>
                                        ) : a.is_active ? (
                                            <span className="text-emerald-600 dark:text-emerald-400 text-xs">active</span>
                                        ) : (
                                            <span className="text-muted-foreground text-xs">inactive</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
