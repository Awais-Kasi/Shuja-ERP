import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine, ChevronLeft, ChevronRight, Receipt, TrendingDown, Wallet } from 'lucide-react';
import { ScreenHelp } from '@/components/screen-help';
import { money, rupees } from '@/lib/format';

type Summary = {
    sales: number;
    expenses: number;
    cash_in: number;
    cash_out: number;
    net: number;
};

type Voucher = {
    number: string | null;
    type: string;
    memo: string | null;
    amount: number;
};

type Props = {
    date: string;
    prevDate: string;
    nextDate: string;
    isToday: boolean;
    summary: Summary;
    vouchers: Voucher[];
};

export default function DayBook({ date, prevDate, nextDate, isToday, summary, vouchers }: Props) {
    const go = (d: string) => router.get('/day-book', { date: d }, { preserveScroll: true, preserveState: true });

    const cards = [
        { label: 'Aaj ki Bikri', value: summary.sales, icon: Receipt, from: '#10b981', to: '#0f766e' },
        { label: 'Aaj ke Kharchay', value: summary.expenses, icon: TrendingDown, from: '#f43f5e', to: '#be185d' },
        { label: 'Cash Aaya', value: summary.cash_in, icon: ArrowDownToLine, from: '#0ea5e9', to: '#2563eb' },
        { label: 'Cash Gaya', value: summary.cash_out, icon: ArrowUpFromLine, from: '#f59e0b', to: '#c2410c' },
    ];

    return (
        <>
            <Head title="Aaj ka Hisaab" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-bold tracking-tight">Aaj ka Hisaab (Roznamcha)</h1>
                    <div className="no-print flex items-center gap-2">
                        <Link href={`/day-book?date=${prevDate}`} className="rounded-lg border p-2 hover:bg-muted" title="Pichla din">
                            <ChevronLeft className="size-4" />
                        </Link>
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => e.target.value && go(e.target.value)}
                            className="border-input bg-background rounded-lg border px-3 py-1.5 text-sm shadow-sm"
                        />
                        <Link
                            href={`/day-book?date=${nextDate}`}
                            className={`rounded-lg border p-2 hover:bg-muted ${isToday ? 'pointer-events-none opacity-40' : ''}`}
                            title="Agla din"
                        >
                            <ChevronRight className="size-4" />
                        </Link>
                    </div>
                </div>

                <ScreenHelp>
                    Yeh poore din ka khulasa hai — kitni bikri hui, kitna kharcha hua, cash kitna andar aaya aur bahar
                    gaya, aur din ke saare vouchers. Upar <b>Print / PDF</b> se is din ka hisaab print kar sakte hain.
                </ScreenHelp>

                {/* Summary cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map((c) => (
                        <div
                            key={c.label}
                            className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md"
                            style={{ backgroundImage: `linear-gradient(135deg, ${c.from} 0%, ${c.to} 100%)` }}
                        >
                            <c.icon className="absolute -bottom-3 -right-3 size-20 opacity-20" />
                            <div className="relative">
                                <div className="text-xs font-medium uppercase tracking-wide text-white/80">{c.label}</div>
                                <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(c.value)}</div>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Net */}
                <div className="bg-card flex items-center justify-between rounded-2xl border p-5 shadow-sm">
                    <span className="flex items-center gap-2 text-sm font-semibold">
                        <Wallet className="size-4 text-indigo-500" /> Din ka Munafa (Bikri − Kharchay)
                    </span>
                    <span className={`font-mono text-2xl font-bold tabular-nums ${summary.net >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                        {rupees(summary.net)}
                    </span>
                </div>

                {/* Roznamcha */}
                <div className="bg-card overflow-hidden rounded-2xl border shadow-sm">
                    <div className="border-b px-4 py-3 text-sm font-semibold">Din ke Vouchers ({vouchers.length})</div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left text-xs uppercase tracking-wide">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Number</th>
                                    <th className="px-4 py-3 font-medium">Type</th>
                                    <th className="px-4 py-3 font-medium">Tafseel</th>
                                    <th className="px-4 py-3 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {vouchers.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="text-muted-foreground px-4 py-10 text-center">
                                            Is din koi voucher post nahi hua.
                                        </td>
                                    </tr>
                                )}
                                {vouchers.map((v, i) => (
                                    <tr key={i} className="hover:bg-muted/40">
                                        <td className="px-4 py-3 font-mono text-xs font-medium">{v.number ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <span className="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-medium capitalize text-indigo-600 dark:text-indigo-400">
                                                {v.type}
                                            </span>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-3">{v.memo ?? '—'}</td>
                                        <td className="px-4 py-3 text-right font-mono font-semibold tabular-nums">{money(v.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}
