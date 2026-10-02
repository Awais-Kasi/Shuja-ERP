import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, PackageMinus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
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
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Line = { component: string; quantity: number; issued_qty: number };
type Order = {
    id: number; number: string | null; date: string; item: string; quantity: number;
    source_warehouse: string; target_warehouse: string; status: string;
    material_cost: number; overhead_cost: number; produced_cost: number; unit_cost: number;
    issue_journal_id: number | null; completion_journal_id: number | null; lines: Line[];
};

export default function ShowWorkOrder({ order }: { order: Order }) {
    const { can } = usePermissions();
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [completeOpen, setCompleteOpen] = useState(false);
    const completeForm = useForm({ overhead: '' });

    const issue = () => router.post(`/manufacturing/work-orders/${order.id}/issue`, {}, { preserveScroll: true });
    const complete = (e: FormEvent) => {
        e.preventDefault();
        completeForm.post(`/manufacturing/work-orders/${order.id}/complete`, { preserveScroll: true, onSuccess: () => setCompleteOpen(false) });
    };

    const statusColor: Record<string, string> = {
        draft: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        in_progress: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        completed: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    };

    return (
        <>
            <Head title={`WO ${order.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/manufacturing/work-orders"><ArrowLeft className="size-4" /></Link></Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                                {order.number}
                                <Badge variant="secondary" className={`capitalize ${statusColor[order.status] ?? ''}`}>{order.status.replace('_', ' ')}</Badge>
                            </h1>
                            <p className="text-muted-foreground text-sm">{money(order.quantity)} × {order.item}</p>
                        </div>
                    </div>
                    {can('manufacturing.workorder.manage') && (
                        <div className="flex gap-2">
                            {order.status === 'draft' && <Button onClick={issue}><PackageMinus className="size-4" /> Issue materials</Button>}
                            {order.status === 'in_progress' && (
                                <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
                                    <DialogTrigger asChild><Button><CheckCircle2 className="size-4" /> Complete production</Button></DialogTrigger>
                                    <DialogContent>
                                        <form onSubmit={complete}>
                                            <DialogHeader>
                                                <DialogTitle>Complete production</DialogTitle>
                                                <DialogDescription>Add any overhead/labour to apply, then receive the finished goods.</DialogDescription>
                                            </DialogHeader>
                                            <div className="grid gap-4 py-4">
                                                <div className="text-muted-foreground flex justify-between text-sm"><span>Material cost</span><span className="font-mono tabular-nums">{money(order.material_cost)}</span></div>
                                                <div className="grid gap-1.5">
                                                    <Label htmlFor="oh">Overhead / labour</Label>
                                                    <Input id="oh" inputMode="decimal" value={completeForm.data.overhead} onChange={(e) => completeForm.setData('overhead', e.target.value)} placeholder="0.00" />
                                                </div>
                                                <div className="flex justify-between text-sm font-medium"><span>Produced cost</span><span className="font-mono tabular-nums">{money(order.material_cost + (parseFloat(completeForm.data.overhead) || 0))}</span></div>
                                            </div>
                                            <DialogFooter><Button type="submit" disabled={completeForm.processing}>Receive finished goods</Button></DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    )}
                </div>

                {errors?.production && <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">{errors.production}</div>}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Material cost" value={order.material_cost} />
                    <Stat label="Overhead" value={order.overhead_cost} />
                    <Stat label="Produced cost" value={order.produced_cost} />
                    <Stat label="Unit cost" value={order.unit_cost} />
                </div>

                <dl className="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-4">
                    <div><dt className="text-muted-foreground text-xs uppercase">From</dt><dd className="mt-0.5 text-sm">{order.source_warehouse}</dd></div>
                    <div><dt className="text-muted-foreground text-xs uppercase">Into</dt><dd className="mt-0.5 text-sm">{order.target_warehouse}</dd></div>
                    {order.issue_journal_id && <div><dt className="text-muted-foreground text-xs uppercase">Issue journal</dt><dd className="mt-0.5 text-sm"><Link className="text-primary underline" href={`/accounting/journals/${order.issue_journal_id}`}>View</Link></dd></div>}
                    {order.completion_journal_id && <div><dt className="text-muted-foreground text-xs uppercase">Completion journal</dt><dd className="mt-0.5 text-sm"><Link className="text-primary underline" href={`/accounting/journals/${order.completion_journal_id}`}>View</Link></dd></div>}
                </dl>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Component</th>
                                <th className="w-32 text-right">Required</th>
                                <th className="w-32 text-right">Issued</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {order.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className="px-4 py-2.5">{l.component}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(l.quantity)}</td>
                                    <td className={`px-4 py-2.5 text-right font-mono tabular-nums ${l.issued_qty >= l.quantity ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'}`}>{money(l.issued_qty)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-xs uppercase tracking-wide">{label}</div>
            <div className="mt-1 font-mono text-xl font-semibold tabular-nums">{money(value)}</div>
        </div>
    );
}
