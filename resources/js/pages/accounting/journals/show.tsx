import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Undo2 } from 'lucide-react';
import { type ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { moneyOrDash } from '@/lib/format';

type Line = {
    id: number;
    account: string;
    cost_center: string | null;
    description: string | null;
    debit: number;
    credit: number;
};

type Journal = {
    id: number;
    number: string | null;
    entry_date: string;
    type: string;
    reference: string | null;
    memo: string | null;
    status: string;
    posted_at: string | null;
    created_by: string | null;
    reverses: string | null;
    lines: Line[];
};

function Meta({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs uppercase tracking-wide">{label}</dt>
            <dd className="mt-0.5 text-sm">{value ?? '—'}</dd>
        </div>
    );
}

export default function ShowJournal({ journal }: { journal: Journal }) {
    const { can } = usePermissions();
    const totalDebit = journal.lines.reduce((s, l) => s + l.debit, 0);
    const totalCredit = journal.lines.reduce((s, l) => s + l.credit, 0);

    const reverse = () => {
        if (confirm(`Reverse journal ${journal.number}? This posts a mirror entry.`)) {
            router.post(`/accounting/journals/${journal.id}/reverse`);
        }
    };

    return (
        <>
            <Head title={`Journal ${journal.number ?? ''}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon">
                            <Link href="/accounting/journals"><ArrowLeft className="size-4" /></Link>
                        </Button>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                                {journal.number}
                                <Badge variant="secondary" className="capitalize">{journal.status}</Badge>
                            </h1>
                            <p className="text-muted-foreground text-sm capitalize">{journal.type} entry</p>
                        </div>
                    </div>
                    {journal.status === 'posted' && journal.type !== 'reversal' && can('accounting.journal.post') && (
                        <Button variant="outline" onClick={reverse}>
                            <Undo2 className="size-4" /> Reverse
                        </Button>
                    )}
                </div>

                <dl className="grid grid-cols-2 gap-4 rounded-xl border p-4 sm:grid-cols-4">
                    <Meta label="Date" value={journal.entry_date} />
                    <Meta label="Reference" value={journal.reference} />
                    <Meta label="Posted" value={journal.posted_at} />
                    <Meta label="Created by" value={journal.created_by} />
                    {journal.memo && <div className="col-span-2 sm:col-span-4"><Meta label="Narration" value={journal.memo} /></div>}
                    {journal.reverses && <Meta label="Reverses" value={journal.reverses} />}
                </dl>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th>Account</th>
                                <th>Description</th>
                                <th className="w-40">Cost center</th>
                                <th className="w-36 text-right">Debit</th>
                                <th className="w-36 text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {journal.lines.map((l) => (
                                <tr key={l.id}>
                                    <td className="px-4 py-2.5">{l.account}</td>
                                    <td className="text-muted-foreground px-4 py-2.5">{l.description ?? '—'}</td>
                                    <td className="px-4 py-2.5">{l.cost_center ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{moneyOrDash(l.debit)}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{moneyOrDash(l.credit)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-2.5">
                                <td colSpan={3} className="text-right">Total</td>
                                <td className="text-right font-mono tabular-nums">{moneyOrDash(totalDebit)}</td>
                                <td className="text-right font-mono tabular-nums">{moneyOrDash(totalCredit)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
