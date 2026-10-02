import { Head, router } from '@inertiajs/react';
import { ArrowLeft, Coins } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/format';

type Line = { account: string; currency: string; foreign_balance: number; closing_rate: number; carrying_base: number; revalued_base: number; adjustment: number };
type Reval = { id: number; date: string; gain: number; loss: number; net: number; journal_id: number | null; journal: string | null; lines: Line[] };

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });
const rate4 = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });

export default function FxShow({ revaluation }: { revaluation: Reval }) {
    const tiles = [
        { label: 'Unrealised gain', value: money(revaluation.gain), from: '#10b981', to: '#0f766e' },
        { label: 'Unrealised loss', value: money(revaluation.loss), from: '#f43f5e', to: '#be185d' },
        { label: 'Net adjustment', value: money(revaluation.net), from: '#6366f1', to: '#4338ca' },
    ];

    return (
        <>
            <Head title={`FX Revaluation — ${revaluation.date}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <Button variant="ghost" size="sm" className="mb-3 -ml-2" onClick={() => router.visit('/accounting/fx-revaluation')}><ArrowLeft className="size-4" /> FX revaluation</Button>
                    <div className="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={gradient('#0369a1', '#0e7490')}>
                        <Coins className="pointer-events-none absolute -bottom-6 -right-4 size-40 opacity-15" />
                        <div className="relative flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <div className="text-xs font-medium uppercase tracking-widest text-sky-100">Foreign exchange revaluation</div>
                                <h1 className="mt-1 text-2xl font-bold tracking-tight">{revaluation.date}</h1>
                            </div>
                            {revaluation.journal_id && (
                                <Button variant="secondary" className="bg-white/15 text-white hover:bg-white/25" onClick={() => router.visit(`/accounting/journals/${revaluation.journal_id}`)}>Journal {revaluation.journal}</Button>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    {tiles.map((t) => (
                        <div key={t.label} className="relative overflow-hidden rounded-2xl p-4 text-white shadow-md" style={gradient(t.from, t.to)}>
                            <div className="text-xs font-medium uppercase tracking-wide text-white/80">{t.label}</div>
                            <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{t.value}</div>
                        </div>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[880px] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr className="[&>th]:px-4 [&>th]:py-2.5 [&>th]:font-medium">
                                <th className="text-left">Account</th><th className="text-left">Ccy</th>
                                <th className="text-right">Foreign balance</th><th className="text-right">Closing rate</th>
                                <th className="text-right">Carrying</th><th className="text-right">Revalued</th><th className="text-right">Adjustment</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {revaluation.lines.map((l, i) => (
                                <tr key={i} className="[&>td]:px-4 [&>td]:py-2.5">
                                    <td className="font-medium">{l.account}</td>
                                    <td className="font-mono text-xs">{l.currency}</td>
                                    <td className="text-right font-mono tabular-nums">{money(l.foreign_balance)}</td>
                                    <td className="text-right font-mono tabular-nums">{rate4(l.closing_rate)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(l.carrying_base)}</td>
                                    <td className="text-right font-mono tabular-nums">{money(l.revalued_base)}</td>
                                    <td className={`text-right font-mono font-semibold tabular-nums ${l.adjustment >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>{money(l.adjustment)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
