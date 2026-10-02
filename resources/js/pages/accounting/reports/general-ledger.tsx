import { Head, router } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { money, moneyOrDash } from '@/lib/format';

type Filters = { account_id: number | null; from: string; to: string };
type Line = { journal_id: number; number: string; date: string; narration: string | null; debit: number; credit: number; balance: number };
type Statement = {
    account: { id: number; code: string; name: string; type: string };
    opening: number;
    lines: Line[];
    closing: number;
};

export default function GeneralLedger({
    accounts,
    filters,
    statement,
}: {
    accounts: { id: number; label: string }[];
    filters: Filters;
    statement: Statement | null;
}) {
    const reload = (patch: Partial<Filters>) => {
        router.get('/accounting/general-ledger', { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="General Ledger" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">General Ledger</h1>
                    <p className="text-muted-foreground text-sm">Account statement with running balance</p>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Account</Label>
                        <Select value={filters.account_id ? String(filters.account_id) : ''} onValueChange={(v) => reload({ account_id: Number(v) })}>
                            <SelectTrigger><SelectValue placeholder="Select account" /></SelectTrigger>
                            <SelectContent>
                                {accounts.map((a) => (
                                    <SelectItem key={a.id} value={String(a.id)}>{a.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="from" className="text-xs">From</Label>
                        <Input id="from" type="date" value={filters.from} onChange={(e) => reload({ from: e.target.value })} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="to" className="text-xs">To</Label>
                        <Input id="to" type="date" value={filters.to} onChange={(e) => reload({ to: e.target.value })} />
                    </div>
                </div>

                {!statement ? (
                    <div className="text-muted-foreground rounded-xl border py-16 text-center text-sm">
                        Select an account to view its ledger.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <div className="bg-muted/30 border-b px-4 py-3 text-sm font-semibold">
                            {statement.account.code} — {statement.account.name}
                        </div>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                    <th className="w-28 text-left">Date</th>
                                    <th className="w-32 text-left">Journal</th>
                                    <th className="text-left">Narration</th>
                                    <th className="w-32 text-right">Debit</th>
                                    <th className="w-32 text-right">Credit</th>
                                    <th className="w-36 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                <tr className="text-muted-foreground bg-muted/20">
                                    <td className="px-4 py-2" colSpan={5}>Opening balance</td>
                                    <td className="px-4 py-2 text-right font-mono tabular-nums">{money(statement.opening)}</td>
                                </tr>
                                {statement.lines.map((l, i) => (
                                    <tr key={i} className="hover:bg-muted/40 cursor-pointer" onClick={() => router.visit(`/accounting/journals/${l.journal_id}`)}>
                                        <td className="px-4 py-2 tabular-nums">{l.date}</td>
                                        <td className="px-4 py-2 font-mono text-xs">{l.number}</td>
                                        <td className="text-muted-foreground px-4 py-2">{l.narration ?? '—'}</td>
                                        <td className="px-4 py-2 text-right font-mono tabular-nums">{moneyOrDash(l.debit)}</td>
                                        <td className="px-4 py-2 text-right font-mono tabular-nums">{moneyOrDash(l.credit)}</td>
                                        <td className="px-4 py-2 text-right font-mono tabular-nums">{money(l.balance)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t-2 font-semibold">
                                <tr className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td colSpan={5} className="text-right">Closing balance</td>
                                    <td className="text-right font-mono tabular-nums">{money(statement.closing)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}
