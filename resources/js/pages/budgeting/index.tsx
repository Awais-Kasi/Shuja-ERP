import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Target } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';

type FiscalYear = { id: number; name: string };
type BudgetRow = { id: number; name: string; fiscal_year: string; status: string; lines: number };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

export default function BudgetingIndex({ budgets, fiscalYears }: { budgets: BudgetRow[]; fiscalYears: FiscalYear[] }) {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="Budgets" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#7c3aed', '#c026d3')}>
                    <Target className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="text-xs font-medium uppercase tracking-widest text-fuchsia-100">Finance</div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Budgets</h1>
                            <p className="mt-1 text-sm text-fuchsia-50/80">Plan income and expense targets, then track variance against the live ledger.</p>
                        </div>
                        {can('budget.manage') && fiscalYears.length > 0 && (
                            <Dialog open={open} onOpenChange={setOpen}>
                                <DialogTrigger asChild><Button className="bg-white text-fuchsia-700 hover:bg-white/90"><Plus className="size-4" /> New budget</Button></DialogTrigger>
                                <CreateDialog fiscalYears={fiscalYears} onDone={() => setOpen(false)} />
                            </Dialog>
                        )}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Budget</th>
                                <th className="text-left">Fiscal year</th>
                                <th className="text-center">Lines</th>
                                <th className="text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {budgets.length === 0 && <tr><td colSpan={4} className="text-muted-foreground px-4 py-10 text-center">No budgets yet.</td></tr>}
                            {budgets.map((b) => (
                                <tr key={b.id} onClick={() => router.visit(`/budgeting/budgets/${b.id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{b.name}</td>
                                    <td>{b.fiscal_year}</td>
                                    <td className="text-center tabular-nums">{b.lines}</td>
                                    <td><Badge variant="secondary" className="capitalize">{b.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function CreateDialog({ fiscalYears, onDone }: { fiscalYears: FiscalYear[]; onDone: () => void }) {
    const form = useForm({ fiscal_year_id: fiscalYears[0] ? String(fiscalYears[0].id) : '', name: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/budgeting/budgets', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="text-destructive text-xs">{form.errors[k]}</p>;

    return (
        <DialogContent className="sm:max-w-md">
            <DialogHeader><DialogTitle>New budget</DialogTitle></DialogHeader>
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-1.5">
                    <Label>Fiscal year</Label>
                    <Select value={form.data.fiscal_year_id} onValueChange={(v) => form.setData('fiscal_year_id', v)}>
                        <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                        <SelectContent>{fiscalYears.map((f) => <SelectItem key={f.id} value={String(f.id)}>{f.name}</SelectItem>)}</SelectContent>
                    </Select>
                    {err('fiscal_year_id')}
                </div>
                <div className="grid gap-1.5"><Label>Name</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Operating Budget" />{err('name')}</div>
                <DialogFooter><Button type="submit" disabled={form.processing}>Create</Button></DialogFooter>
            </form>
        </DialogContent>
    );
}
