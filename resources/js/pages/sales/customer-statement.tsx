import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, MessageCircle } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { ScreenHelp } from '@/components/screen-help';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money, whatsappUrl } from '@/lib/format';

type Party = { id: number; code: string; name: string; phone?: string | null };
type Row = { date: string; journal_id: number; voucher: string | null; detail: string; debit: number; credit: number; balance: number };

export default function CustomerStatement({
    party, opening, rows, closing, totalDebit, totalCredit, from, to,
}: {
    party: Party; opening: number; rows: Row[]; closing: number; totalDebit: number; totalCredit: number; from: string | null; to: string | null;
}) {
    const [f, setF] = useState(from ?? '');
    const [t, setT] = useState(to ?? '');
    const company = (usePage().props as { tenant?: { current?: { name?: string } } }).tenant?.current?.name ?? '';

    const apply = (e: FormEvent) => {
        e.preventDefault();
        router.get(`/sales/customer-ledger/${party.id}`, { from: f || undefined, to: t || undefined }, { preserveState: true });
    };

    const waText = `Assalam o Alaikum ${party.name}, aap ka mojooda baqaya Rs ${money(closing)} hai. Baraye meharbani ada karne ki zehmat karein. Shukriya.${company ? ` — ${company}` : ''}`;

    return (
        <>
            <Head title={`Statement — ${party.name}`} />
            <div className="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/sales/customer-ledger"><ArrowLeft className="size-4" /></Link></Button>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">{party.name}</h1>
                        <p className="text-muted-foreground text-sm">Customer account statement <span className="font-mono text-xs">{party.code}</span></p>
                    </div>
                    <a
                        href={whatsappUrl(party.phone, waText)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="no-print ml-auto inline-flex items-center gap-1.5 rounded-lg bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-400"
                    >
                        <MessageCircle className="size-4" /> WhatsApp
                    </a>
                </div>

                <ScreenHelp>
                    Yeh is customer ka poora <b>khata</b> hai — har kharedari aur har payment, sath mein chalta hua
                    balance. <b>WhatsApp</b> button se mojooda baqaya customer ko bhej dein. Kisi bhi row par click karein
                    to us ki accounting entry khul jati hai.
                </ScreenHelp>

                <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5"><Label htmlFor="from" className="text-xs">From</Label><Input id="from" type="date" value={f} onChange={(e) => setF(e.target.value)} className="w-40" /></div>
                    <div className="grid gap-1.5"><Label htmlFor="to" className="text-xs">To</Label><Input id="to" type="date" value={t} onChange={(e) => setT(e.target.value)} className="w-40" /></div>
                    <Button type="submit" variant="outline">Apply</Button>
                    {(from || to) && <Button type="button" variant="ghost" onClick={() => router.get(`/sales/customer-ledger/${party.id}`)}>Clear</Button>}
                </form>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Opening balance</div><div className="mt-1 font-mono text-lg tabular-nums">{money(opening)}</div></div>
                    <div className="rounded-xl border p-4"><div className="text-muted-foreground text-xs uppercase">Transactions</div><div className="mt-1 font-mono text-lg tabular-nums">{rows.length}</div></div>
                    <div className="rounded-xl border bg-muted/30 p-4"><div className="text-muted-foreground text-xs uppercase">Closing balance (owed to us)</div><div className={`mt-1 font-mono text-lg font-semibold tabular-nums ${closing >= 0 ? '' : 'text-emerald-600 dark:text-emerald-400'}`}>{money(closing)}</div></div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="w-28 text-left">Date</th>
                                <th className="w-28 text-left">Voucher</th>
                                <th className="text-left">Detail</th>
                                <th className="w-32 text-right">Charged (Dr)</th>
                                <th className="w-32 text-right">Paid (Cr)</th>
                                <th className="w-36 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            <tr className="bg-muted/20 [&>td]:px-4 [&>td]:py-2 font-medium">
                                <td colSpan={5} className="text-muted-foreground">Opening balance</td>
                                <td className="text-right font-mono tabular-nums">{money(opening)}</td>
                            </tr>
                            {rows.length === 0 && <tr><td colSpan={6} className="text-muted-foreground px-4 py-10 text-center">No transactions in this period.</td></tr>}
                            {rows.map((r, i) => (
                                <tr key={i} onClick={() => router.visit(`/accounting/journals/${r.journal_id}`)} className="hover:bg-muted/40 cursor-pointer [&>td]:px-4 [&>td]:py-2">
                                    <td className="tabular-nums">{r.date}</td>
                                    <td className="font-mono text-xs">{r.voucher ?? '—'}</td>
                                    <td>{r.detail}</td>
                                    <td className="text-right font-mono tabular-nums">{r.debit ? money(r.debit) : '—'}</td>
                                    <td className="text-right font-mono tabular-nums">{r.credit ? money(r.credit) : '—'}</td>
                                    <td className="text-right font-mono tabular-nums">{money(r.balance)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 font-semibold">
                            <tr className="[&>td]:px-4 [&>td]:py-3">
                                <td colSpan={3} className="text-right">Totals</td>
                                <td className="text-right font-mono tabular-nums">{money(totalDebit)}</td>
                                <td className="text-right font-mono tabular-nums">{money(totalCredit)}</td>
                                <td className="text-right font-mono tabular-nums">{money(closing)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">A positive balance is money this customer owes you. Click any row to open its accounting entry.</p>
            </div>
        </>
    );
}
