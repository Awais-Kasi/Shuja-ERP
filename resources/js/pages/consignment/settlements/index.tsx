import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { money } from '@/lib/format';

type Row = { id: number; number: string | null; date: string; dispatch: string | null; agent: string | null; total: number; status: string };
type Paginator<T> = { data: T[]; total: number };

export default function SettlementsIndex({ settlements }: { settlements: Paginator<Row> }) {
    return (
        <>
            <Head title="Consignment Settlements" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Consignment Settlements</h1>
                    <p className="text-muted-foreground text-sm">{settlements.total} settlements · create one from a dispatch</p>
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:text-left [&>th]:font-medium">
                                <th className="w-32">Number</th>
                                <th className="w-28">Date</th>
                                <th className="w-32">Dispatch</th>
                                <th>Agent</th>
                                <th className="w-36 text-right">Total</th>
                                <th className="w-24">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {settlements.data.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No settlements yet.</td></tr>}
                            {settlements.data.map((s) => (
                                <tr key={s.id} onClick={() => router.visit(`/consignment/settlements/${s.id}`)} className="hover:bg-muted/40 cursor-pointer">
                                    <td className="px-4 py-2.5 font-mono text-xs">{s.number ?? '—'}</td>
                                    <td className="px-4 py-2.5 tabular-nums">{s.date}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs">{s.dispatch ?? '—'}</td>
                                    <td className="px-4 py-2.5">{s.agent ?? '—'}</td>
                                    <td className="px-4 py-2.5 text-right font-mono tabular-nums">{money(s.total)}</td>
                                    <td className="px-4 py-2.5"><Badge variant="secondary" className="capitalize">{s.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
