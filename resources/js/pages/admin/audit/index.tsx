import { Head, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Log = { id: number; at: string | null; user: string; event: string; model: string; record: number; changed: string; url: string | null };
type Paginator<T> = { data: T[]; total: number; current_page: number; last_page: number; links: { url: string | null; label: string; active: boolean }[] };
type Filters = { event: string | null; from: string | null; to: string | null };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });
const eventStyle: Record<string, string> = {
    created: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    updated: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    deleted: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
};

export default function AuditIndex({ logs, filters }: { logs: Paginator<Log>; filters: Filters }) {
    const setFilter = (patch: Partial<Filters>) => router.get('/admin/audit', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Audit Log" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#334155', '#0f172a')}>
                    <ScrollText className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                    <div className="relative">
                        <div className="text-xs font-medium uppercase tracking-widest text-slate-300">Administration</div>
                        <h1 className="mt-1 text-3xl font-bold tracking-tight">Audit Log</h1>
                        <p className="mt-1 text-sm text-slate-300/90">Who changed what, and when — across master data and security.</p>
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Event</Label>
                        <Select value={filters.event ?? 'all'} onValueChange={(v) => setFilter({ event: v === 'all' ? null : v })}>
                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value="all">All events</SelectItem><SelectItem value="created">Created</SelectItem><SelectItem value="updated">Updated</SelectItem><SelectItem value="deleted">Deleted</SelectItem></SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5"><Label className="text-xs">From</Label><Input type="date" value={filters.from ?? ''} onChange={(e) => setFilter({ from: e.target.value || null })} className="w-40" /></div>
                    <div className="grid gap-1.5"><Label className="text-xs">To</Label><Input type="date" value={filters.to ?? ''} onChange={(e) => setFilter({ to: e.target.value || null })} className="w-40" /></div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[820px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground"><tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium"><th className="text-left">When</th><th className="text-left">User</th><th className="text-left">Event</th><th className="text-left">Record</th><th className="text-left">Changed fields</th></tr></thead>
                        <tbody className="divide-y">
                            {logs.data.length === 0 && <tr><td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">No audit entries for this filter.</td></tr>}
                            {logs.data.map((l) => (
                                <tr key={l.id} className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td className="whitespace-nowrap text-xs">{l.at}</td>
                                    <td>{l.user}</td>
                                    <td><Badge variant="secondary" className={`capitalize ${eventStyle[l.event] ?? ''}`}>{l.event}</Badge></td>
                                    <td><span className="font-medium">{l.model}</span> <span className="text-muted-foreground font-mono text-xs">#{l.record}</span></td>
                                    <td className="text-muted-foreground text-xs">{l.changed || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {logs.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-center gap-1">
                        {logs.links.map((lnk, i) => (
                            <button key={i} disabled={!lnk.url} onClick={() => lnk.url && router.visit(lnk.url, { preserveScroll: true, preserveState: true })}
                                className={`min-w-9 rounded-md border px-3 py-1.5 text-sm ${lnk.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted disabled:opacity-40'}`}
                                dangerouslySetInnerHTML={{ __html: lnk.label }} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
