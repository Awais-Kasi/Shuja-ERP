import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type JournalRow = {
    id: number;
    number: string | null;
    entry_date: string;
    type: string;
    reference: string | null;
    memo: string | null;
    status: string;
    amount: number;
    lines_count: number;
};

type Paginator<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

const statusStyle: Record<string, string> = {
    posted: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    draft: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    void: 'bg-muted text-muted-foreground',
};

export default function JournalsIndex({ journals }: { journals: Paginator<JournalRow> }) {
    const { can } = usePermissions();

    return (
        <>
            <Head title="Journal Entries" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Journal Entries</h1>
                        <p className="text-muted-foreground text-sm">{journals.total} journals</p>
                    </div>
                    {can('accounting.journal.create') && (
                        <Button asChild>
                            <Link href="/accounting/journals/create">
                                <Plus className="size-4" /> New journal
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th className="w-24">Type</th>
                                <th>Narration</th>
                                <th className="w-36 text-right">Amount</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {journals.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">
                                        No journals yet.
                                    </td>
                                </tr>
                            )}
                            {journals.data.map((j) => (
                                <tr
                                    key={j.id}
                                    onClick={() => router.visit(`/accounting/journals/${j.id}`)}
                                    className="hover:bg-muted/40 cursor-pointer"
                                >
                                    <td className="px-4 py-2.5 font-mono text-xs">{j.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{j.entry_date}</td>
                                    <td className="px-4 py-2.5 capitalize">{j.type}</td>
                                    <td className="px-4 py-2.5">{j.memo ?? j.reference ?? <span className="text-muted-foreground">—</span>}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(j.amount)}</td>
                                    <td className="px-4 py-2.5">
                                        <Badge variant="secondary" className={`capitalize ${statusStyle[j.status] ?? ''}`}>
                                            {j.status}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {journals.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {journals.links.map((link, i) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
