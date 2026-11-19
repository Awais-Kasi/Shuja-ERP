import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, BookOpen, MessageCircle, Phone } from 'lucide-react';
import { useState } from 'react';
import { ScreenHelp } from '@/components/screen-help';
import { money, rupees, whatsappUrl } from '@/lib/format';

type Party = {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    balance: number;
    last_date: string | null;
};

type Props = {
    receivables: Party[];
    payables: Party[];
};

type Tab = 'lena' | 'dena';

export default function Udhaar({ receivables, payables }: Props) {
    const [tab, setTab] = useState<Tab>('lena');
    const company = (usePage().props as { tenant?: { current?: { name?: string } } }).tenant?.current?.name ?? '';

    const lenaTotal = receivables.reduce((s, p) => s + p.balance, 0);
    const denaTotal = payables.reduce((s, p) => s + p.balance, 0);

    const rows = tab === 'lena' ? receivables : payables;
    const isLena = tab === 'lena';

    return (
        <>
            <Head title="Udhaar" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Udhaar — Lena / Dena</h1>
                </div>

                <ScreenHelp>
                    Yahan aik nazar mein dekhein: <b>Lena hai</b> (jin customers ne aap ko paisa dena hai) aur{' '}
                    <b>Dena hai</b> (jin suppliers ko aap ne paisa dena hai). Sabse bara baqaya upar. WhatsApp button se
                    customer ko yaad-dehani bhej sakte hain.
                </ScreenHelp>

                {/* Totals */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <button
                        type="button"
                        onClick={() => setTab('lena')}
                        className={`rounded-2xl border p-5 text-left shadow-sm transition-all ${isLena ? 'ring-2 ring-emerald-500' : 'hover:-translate-y-0.5 hover:shadow-md'}`}
                    >
                        <div className="flex items-center gap-2 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                            <ArrowDownLeft className="size-4" /> Lena hai (Customers)
                        </div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300">
                            {rupees(lenaTotal)}
                        </div>
                        <div className="text-muted-foreground mt-1 text-xs">{receivables.length} parties</div>
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('dena')}
                        className={`rounded-2xl border p-5 text-left shadow-sm transition-all ${!isLena ? 'ring-2 ring-rose-500' : 'hover:-translate-y-0.5 hover:shadow-md'}`}
                    >
                        <div className="flex items-center gap-2 text-sm font-medium text-rose-600 dark:text-rose-400">
                            <ArrowUpRight className="size-4" /> Dena hai (Suppliers)
                        </div>
                        <div className="mt-2 font-mono text-2xl font-bold tabular-nums text-rose-700 dark:text-rose-300">
                            {rupees(denaTotal)}
                        </div>
                        <div className="text-muted-foreground mt-1 text-xs">{payables.length} parties</div>
                    </button>
                </div>

                {/* List */}
                <div className="bg-card overflow-hidden rounded-2xl border shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs uppercase tracking-wide">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Party</th>
                                    <th className="px-4 py-3 font-medium">Phone</th>
                                    <th className="px-4 py-3 font-medium">Aakhri lein-den</th>
                                    <th className="px-4 py-3 text-right font-medium">{isLena ? 'Lena hai' : 'Dena hai'}</th>
                                    <th className="px-4 py-3 text-right font-medium">Khata</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground px-4 py-10 text-center">
                                            {isLena ? 'Kisi customer ka baqaya nahi.' : 'Kisi supplier ka baqaya nahi.'} 🎉
                                        </td>
                                    </tr>
                                )}
                                {rows.map((p) => {
                                    const ledger = isLena ? `/sales/customer-ledger/${p.id}` : `/purchase/supplier-ledger/${p.id}`;
                                    const waText = isLena
                                        ? `Assalam o Alaikum ${p.name}, aap ke zimma humara baqaya Rs ${money(p.balance)} hai. Baraye meharbani ada karne ki zehmat karein. Shukriya.${company ? ` — ${company}` : ''}`
                                        : '';
                                    return (
                                        <tr key={p.id} className="hover:bg-muted/40">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{p.name}</div>
                                                <div className="text-muted-foreground font-mono text-xs">{p.code}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {p.phone ? (
                                                    <a href={`tel:${p.phone}`} className="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground">
                                                        <Phone className="size-3.5" /> {p.phone}
                                                    </a>
                                                ) : (
                                                    <span className="text-muted-foreground/50">—</span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3 tabular-nums">{p.last_date ?? '—'}</td>
                                            <td className={`px-4 py-3 text-right font-mono font-semibold tabular-nums ${isLena ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300'}`}>
                                                {money(p.balance)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center justify-end gap-2">
                                                    {isLena && (
                                                        <a
                                                            href={whatsappUrl(p.phone, waText)}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            title="WhatsApp par yaad-dehani bhejein"
                                                            className="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-2.5 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-400"
                                                        >
                                                            <MessageCircle className="size-3.5" /> WhatsApp
                                                        </a>
                                                    )}
                                                    <Link
                                                        href={ledger}
                                                        className="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted"
                                                    >
                                                        <BookOpen className="size-3.5" /> Khata
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                            {rows.length > 0 && (
                                <tfoot className="bg-muted/40 font-semibold">
                                    <tr>
                                        <td className="px-4 py-3" colSpan={3}>
                                            Total
                                        </td>
                                        <td className="px-4 py-3 text-right font-mono tabular-nums">{money(isLena ? lenaTotal : denaTotal)}</td>
                                        <td className="px-4 py-3" />
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}
