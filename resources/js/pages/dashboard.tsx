import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    BarChart3,
    Boxes,
    Building2,
    CalendarRange,
    Clock,
    Contact,
    Factory,
    FileText,
    Landmark,
    type LucideIcon,
    Package,
    Percent,
    Receipt,
    ShoppingCart,
    Sparkles,
    Trophy,
    Truck,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { usePermissions } from '@/hooks/use-permissions';
import { money } from '@/lib/format';

type Trend = { month: string; revenue: number; expense: number };
type RecentRow = { number: string | null; date: string; type: string; memo: string | null; amount: number };
type TopItem = { code: string; name: string; value: number };
type Party = { name: string; balance: number };
type Payroll = { number: string | null; period: string | null; status: string | null; employees: number; gross: number; net: number } | null;

type DashboardProps = {
    kpis: { cash: number; bank: number; receivables: number; payables: number; inventory: number; input_tax: number };
    stats: { members: number; currencies: number; periods: number; openPeriods: number };
    counts: { customers: number; suppliers: number; items: number; employees: number };
    trend: Trend[];
    recent: RecentRow[];
    topItems: TopItem[];
    receivables: Party[];
    payables: Party[];
    payroll: Payroll;
    fiscalYear: { name: string; starts_on: string; ends_on: string; status: string } | null;
    role: string | null;
};

const gradient = (from: string, to: string) => ({ backgroundImage: `linear-gradient(135deg, ${from} 0%, ${to} 100%)` });

function useClock() {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const t = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(t);
    }, []);
    return now;
}

/** Ease-out count-up for KPI figures. */
function useCountUp(target: number, duration = 900) {
    const [value, setValue] = useState(0);
    const ref = useRef(0);
    useEffect(() => {
        let raf = 0;
        const from = ref.current;
        const start = performance.now();
        const tick = (t: number) => {
            const p = Math.min(1, (t - start) / duration);
            const eased = 1 - Math.pow(1 - p, 3);
            const v = from + (target - from) * eased;
            ref.current = v;
            setValue(v);
            if (p < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [target, duration]);
    return value;
}

type Tile = { label: string; value: number; icon: LucideIcon; from: string; to: string };
type Module = { title: string; desc: string; href: string; icon: LucideIcon; from: string; to: string; permission?: string };

export default function Dashboard({ kpis, stats, counts, trend, recent, topItems, receivables, payables, payroll, fiscalYear, role }: DashboardProps) {
    const { auth, tenant } = usePage().props;
    const { can } = usePermissions();
    const now = useClock();
    const currency = tenant?.current?.base_currency ?? 'PKR';
    const company = tenant?.current;

    const tiles: Tile[] = [
        { label: 'Cash in Hand', value: kpis.cash, icon: Wallet, from: '#10b981', to: '#0f766e' },
        { label: 'Bank Accounts', value: kpis.bank, icon: Landmark, from: '#0ea5e9', to: '#2563eb' },
        { label: 'Customer Due', value: kpis.receivables, icon: ArrowDownLeft, from: '#8b5cf6', to: '#6d28d9' },
        { label: 'Supplier Due', value: kpis.payables, icon: ArrowUpRight, from: '#f43f5e', to: '#be185d' },
        { label: 'Inventory Value', value: kpis.inventory, icon: Boxes, from: '#f59e0b', to: '#c2410c' },
        { label: 'Net Tax', value: kpis.input_tax, icon: Percent, from: '#06b6d4', to: '#0d9488' },
    ];

    const chips = [
        { label: 'Customers', value: counts.customers, icon: Contact, color: '#7c3aed' },
        { label: 'Suppliers', value: counts.suppliers, icon: Building2, color: '#2563eb' },
        { label: 'Items', value: counts.items, icon: Package, color: '#ea580c' },
        { label: 'Employees', value: counts.employees, icon: Users, color: '#0d9488' },
    ];

    const modules: Module[] = [
        { title: 'Accounts', desc: 'Ledger, journals & trial balance', href: '/accounting/journals', icon: Landmark, from: '#10b981', to: '#0f766e', permission: 'accounting.journal.view' },
        { title: 'Inventory', desc: 'Items, warehouses & stock', href: '/inventory/items', icon: Boxes, from: '#0ea5e9', to: '#1d4ed8', permission: 'inventory.item.view' },
        { title: 'Purchase', desc: 'Orders, receipts & bills', href: '/purchase/orders', icon: ShoppingCart, from: '#8b5cf6', to: '#6d28d9', permission: 'purchase.order.manage' },
        { title: 'Sales', desc: 'Orders, invoices & AR', href: '/sales/orders', icon: Receipt, from: '#f43f5e', to: '#be185d', permission: 'sales.order.manage' },
        { title: 'Manufacturing', desc: 'BOMs & work orders', href: '/manufacturing/work-orders', icon: Factory, from: '#06b6d4', to: '#0f766e', permission: 'manufacturing.workorder.manage' },
        { title: 'Consignment', desc: 'Dispatch, landed cost & settle', href: '/consignment/dispatches', icon: Truck, from: '#6366f1', to: '#1e3a8a', permission: 'consignment.manage' },
        { title: 'HR & Payroll', desc: 'Employees, attendance & payroll', href: '/hr/employees', icon: Users, from: '#d946ef', to: '#6b21a8', permission: 'hr.employee.view' },
        { title: 'Reports', desc: 'Trial balance & ledgers', href: '/accounting/trial-balance', icon: BarChart3, from: '#f59e0b', to: '#c2410c', permission: 'accounting.report.view' },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <style>{`
                @keyframes dashRise { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
                @media (prefers-reduced-motion: reduce){ .dash-anim{animation:none!important} }
            `}</style>
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Banner */}
                <div className="dash-anim relative overflow-hidden rounded-2xl p-6 text-white shadow-lg" style={{ ...gradient('#312e81', '#6d28d9'), animation: 'dashRise .5s ease-out both' }}>
                    <div className="pointer-events-none absolute inset-0 opacity-[0.12]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, white 1px, transparent 0)', backgroundSize: '22px 22px' }} />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-widest text-indigo-200">
                                <Sparkles className="size-4" /> {company?.name ?? 'Shuja ERP'}
                            </div>
                            <h1 className="mt-1 text-3xl font-bold tracking-tight">Welcome back, {auth.user.name.split(' ')[0]}</h1>
                            <p className="mt-1 text-sm text-indigo-100/80">
                                {role ? <>Signed in as <span className="font-semibold text-white">{role}</span></> : 'Operational overview'}
                                {' · '}Base currency <span className="font-mono font-semibold text-white">{currency}</span>
                            </p>
                        </div>
                        <div className="text-right">
                            <div className="flex items-center justify-end gap-2 font-mono text-3xl font-bold tabular-nums">
                                <Clock className="size-6 text-indigo-300" />
                                {now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                            </div>
                            <div className="mt-1 text-sm text-indigo-100/80">
                                {now.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
                            </div>
                        </div>
                    </div>
                </div>

                {/* KPI tiles */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    {tiles.map((t, i) => (
                        <KpiTile key={t.label} tile={t} currency={currency} index={i} />
                    ))}
                </div>

                {/* Quick counts */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {chips.map((c, i) => (
                        <div
                            key={c.label}
                            className="dash-anim bg-card flex items-center gap-4 rounded-2xl border p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
                            style={{ animation: `dashRise .5s ease-out ${0.15 + i * 0.05}s both` }}
                        >
                            <div className="flex size-11 items-center justify-center rounded-xl" style={{ backgroundColor: `${c.color}1a`, color: c.color }}>
                                <c.icon className="size-5" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold tabular-nums leading-none">{c.value}</div>
                                <div className="text-muted-foreground mt-1 text-xs uppercase tracking-wide">{c.label}</div>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Chart + net position */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="dash-anim lg:col-span-2" style={{ animation: 'dashRise .5s ease-out .25s both' }}>
                        <TrendChart data={trend} currency={currency} />
                    </div>
                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .3s both' }}>
                        <div className="mb-4 flex items-center gap-2 text-sm font-semibold"><BarChart3 className="size-4 text-indigo-500" /> Net Position</div>
                        <dl className="space-y-3 text-sm">
                            <PositionRow label="Liquid (cash + bank)" value={kpis.cash + kpis.bank} currency={currency} accent="#10b981" />
                            <PositionRow label="Inventory on hand" value={kpis.inventory} currency={currency} accent="#f59e0b" />
                            <PositionRow label="Owed by customers" value={kpis.receivables} currency={currency} accent="#8b5cf6" />
                            <PositionRow label="Owed to suppliers" value={kpis.payables} currency={currency} accent="#f43f5e" negative />
                        </dl>
                        <div className="mt-4 flex items-center justify-between border-t pt-3">
                            <span className="text-muted-foreground text-sm">Working capital</span>
                            <span className="font-mono text-lg font-bold tabular-nums">{money(kpis.cash + kpis.bank + kpis.inventory + kpis.receivables - kpis.payables)}</span>
                        </div>
                    </div>
                </div>

                {/* Recent activity + top items */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .35s both' }}>
                        <div className="mb-4 flex items-center justify-between">
                            <div className="flex items-center gap-2 text-sm font-semibold"><FileText className="size-4 text-indigo-500" /> Recent Activity</div>
                            {can('accounting.journal.view') && <Link href="/accounting/journals" className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">View all</Link>}
                        </div>
                        <div className="divide-y">
                            {recent.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">No journals posted yet.</p>}
                            {recent.map((r, i) => (
                                <div key={i} className="flex items-center justify-between py-2.5">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono text-xs font-medium">{r.number ?? '—'}</span>
                                            <span className="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-medium capitalize text-indigo-600 dark:text-indigo-400">{r.type}</span>
                                        </div>
                                        <div className="text-muted-foreground truncate text-xs">{r.memo ?? '—'} · {r.date}</div>
                                    </div>
                                    <span className="ml-3 shrink-0 font-mono text-sm font-semibold tabular-nums">{money(r.amount)}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .4s both' }}>
                        <div className="mb-4 flex items-center gap-2 text-sm font-semibold"><Trophy className="size-4 text-amber-500" /> Top Items by Value</div>
                        <TopItems items={topItems} currency={currency} />
                    </div>
                </div>

                {/* Receivables / Payables / Payroll */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .42s both' }}>
                        <div className="mb-3 flex items-center gap-2 text-sm font-semibold"><ArrowDownLeft className="size-4 text-violet-500" /> Receivables by Customer</div>
                        <PartyList rows={receivables} accent="#8b5cf6" emptyLabel="No customer balances." />
                    </div>
                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .46s both' }}>
                        <div className="mb-3 flex items-center gap-2 text-sm font-semibold"><ArrowUpRight className="size-4 text-rose-500" /> Payables by Supplier</div>
                        <PartyList rows={payables} accent="#f43f5e" emptyLabel="No supplier balances." />
                    </div>
                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .5s both' }}>
                        <div className="mb-3 flex items-center justify-between">
                            <div className="flex items-center gap-2 text-sm font-semibold"><Wallet className="size-4 text-indigo-500" /> Latest Payroll</div>
                            {payroll && can('hr.payroll.run') && <Link href="/hr/payroll/statutory-report" className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">Report</Link>}
                        </div>
                        {payroll ? (
                            <div className="space-y-2 text-sm">
                                <div className="flex items-center justify-between"><span className="text-muted-foreground">Period</span><span className="font-medium">{payroll.period}</span></div>
                                <div className="flex items-center justify-between"><span className="text-muted-foreground">Employees</span><span className="font-medium tabular-nums">{payroll.employees}</span></div>
                                <div className="flex items-center justify-between"><span className="text-muted-foreground">Gross</span><span className="font-mono tabular-nums">{money(payroll.gross)}</span></div>
                                <div className="flex items-center justify-between border-t pt-2"><span className="text-muted-foreground">Net</span><span className="font-mono text-base font-bold tabular-nums">{money(payroll.net)}</span></div>
                                <div className="pt-1"><span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium capitalize text-emerald-600 dark:text-emerald-400">{payroll.status?.replace('_', ' ')}</span></div>
                            </div>
                        ) : <p className="text-muted-foreground py-6 text-center text-sm">No payroll runs yet.</p>}
                    </div>
                </div>

                {/* Modules */}
                <div>
                    <h2 className="text-muted-foreground mb-3 text-sm font-semibold uppercase tracking-wide">Modules</h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {modules.map((m, i) => {
                            const allowed = !m.permission || can(m.permission);
                            const cardClass = `dash-anim group relative flex h-32 w-full flex-col justify-between overflow-hidden rounded-2xl p-4 text-left text-white shadow-md transition-all ${allowed ? 'cursor-pointer hover:-translate-y-1 hover:shadow-xl' : 'opacity-60'}`;
                            const style = { ...gradient(m.from, m.to), animation: `dashRise .5s ease-out ${0.4 + i * 0.05}s both` };
                            const inner = (
                                <>
                                    <m.icon className="absolute -bottom-4 -right-4 size-24 opacity-20 transition-transform group-hover:scale-110" />
                                    <div className="relative flex items-start justify-between">
                                        <div className="flex size-11 items-center justify-center rounded-xl bg-white/20 backdrop-blur">
                                            <m.icon className="size-6" />
                                        </div>
                                    </div>
                                    <div className="relative">
                                        <div className="text-lg font-bold">{m.title}</div>
                                        <div className="text-xs text-white/80">{m.desc}</div>
                                    </div>
                                </>
                            );
                            return allowed ? (
                                <button key={m.title} type="button" onClick={() => router.visit(m.href)} style={style} className={cardClass}>{inner}</button>
                            ) : (
                                <div key={m.title} style={style} className={cardClass}>{inner}</div>
                            );
                        })}
                    </div>
                </div>

                {/* Fiscal + workspace */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .5s both' }}>
                        <div className="mb-3 flex items-center gap-2 text-sm font-semibold"><CalendarRange className="size-4 text-indigo-500" /> Fiscal Year</div>
                        {fiscalYear ? (
                            <div className="space-y-2 text-sm">
                                <div className="text-xl font-bold">{fiscalYear.name}</div>
                                <div className="text-muted-foreground tabular-nums">{fiscalYear.starts_on} → {fiscalYear.ends_on}</div>
                                <div className="flex gap-4 pt-1">
                                    <span><span className="text-muted-foreground">Status </span><span className="font-medium capitalize">{fiscalYear.status}</span></span>
                                    <span><span className="text-muted-foreground">Open periods </span><span className="font-medium tabular-nums">{stats.openPeriods}/{stats.periods}</span></span>
                                </div>
                            </div>
                        ) : <p className="text-muted-foreground text-sm">No fiscal year configured.</p>}
                    </div>

                    <div className="dash-anim bg-card rounded-2xl border p-5 shadow-sm" style={{ animation: 'dashRise .5s ease-out .55s both' }}>
                        <div className="mb-3 flex items-center gap-2 text-sm font-semibold"><Building2 className="size-4 text-emerald-500" /> Workspace</div>
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div><dt className="text-muted-foreground text-xs">Company</dt><dd className="font-medium">{company?.name ?? '—'}</dd></div>
                            <div><dt className="text-muted-foreground text-xs">Your role</dt><dd className="font-medium">{role ?? '—'}</dd></div>
                            <div><dt className="text-muted-foreground text-xs">Team members</dt><dd className="font-medium tabular-nums">{stats.members}</dd></div>
                            <div><dt className="text-muted-foreground text-xs">Currencies</dt><dd className="font-medium tabular-nums">{stats.currencies}</dd></div>
                        </dl>
                    </div>
                </div>
            </div>
        </>
    );
}

function KpiTile({ tile, currency, index }: { tile: Tile; currency: string; index: number }) {
    const v = useCountUp(tile.value);
    return (
        <div
            className="dash-anim relative overflow-hidden rounded-2xl p-4 text-white shadow-md transition-transform hover:-translate-y-1"
            style={{ ...gradient(tile.from, tile.to), animation: `dashRise .5s ease-out ${0.05 + index * 0.05}s both` }}
        >
            <tile.icon className="absolute -bottom-3 -right-3 size-20 opacity-20" />
            <div className="relative">
                <div className="text-xs font-medium uppercase tracking-wide text-white/80">{tile.label}</div>
                <div className="mt-2 font-mono text-2xl font-bold tabular-nums">{money(v)}</div>
                <div className="text-[11px] font-medium text-white/70">{currency}</div>
            </div>
        </div>
    );
}

function PositionRow({ label, value, currency, accent, negative }: { label: string; value: number; currency: string; accent: string; negative?: boolean }) {
    return (
        <div className="flex items-center justify-between">
            <span className="flex items-center gap-2 text-muted-foreground">
                <span className="size-2 rounded-full" style={{ backgroundColor: accent }} />
                {label}
            </span>
            <span className={`font-mono font-medium tabular-nums ${negative ? 'text-rose-600 dark:text-rose-400' : ''}`}>
                {money(value)} <span className="text-muted-foreground text-xs">{currency}</span>
            </span>
        </div>
    );
}

function TrendChart({ data, currency }: { data: Trend[]; currency: string }) {
    const [grown, setGrown] = useState(false);
    useEffect(() => {
        const t = setTimeout(() => setGrown(true), 80);
        return () => clearTimeout(t);
    }, []);
    const max = Math.max(1, ...data.flatMap((d) => [d.revenue, d.expense]));

    return (
        <div className="bg-card h-full rounded-2xl border p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-sm font-semibold"><BarChart3 className="size-4 text-indigo-500" /> Revenue vs Expense</div>
                <div className="flex items-center gap-4 text-xs">
                    <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm" style={gradient('#6366f1', '#4338ca')} /> Revenue</span>
                    <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm" style={gradient('#f43f5e', '#be185d')} /> Expense</span>
                </div>
            </div>
            <div className="flex h-48 items-end gap-3 sm:gap-6">
                {data.map((d) => (
                    <div key={d.month} className="flex flex-1 flex-col items-center gap-2">
                        <div className="flex h-40 w-full items-end justify-center gap-1.5">
                            <div
                                title={`Revenue: ${money(d.revenue)} ${currency}`}
                                className="w-4 rounded-t-md transition-[height] duration-700 ease-out sm:w-5"
                                style={{ ...gradient('#6366f1', '#4338ca'), height: grown ? `${Math.max(2, (d.revenue / max) * 100)}%` : '0%' }}
                            />
                            <div
                                title={`Expense: ${money(d.expense)} ${currency}`}
                                className="w-4 rounded-t-md transition-[height] duration-700 ease-out sm:w-5"
                                style={{ ...gradient('#f43f5e', '#be185d'), height: grown ? `${Math.max(2, (d.expense / max) * 100)}%` : '0%' }}
                            />
                        </div>
                        <span className="text-muted-foreground text-xs">{d.month}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function PartyList({ rows, accent, emptyLabel }: { rows: Party[]; accent: string; emptyLabel: string }) {
    if (rows.length === 0) {
        return <p className="text-muted-foreground py-6 text-center text-sm">{emptyLabel}</p>;
    }
    const max = Math.max(1, ...rows.map((r) => r.balance));
    return (
        <div className="space-y-3">
            {rows.map((r) => (
                <div key={r.name}>
                    <div className="mb-1 flex items-center justify-between text-sm">
                        <span className="truncate">{r.name}</span>
                        <span className="ml-2 shrink-0 font-mono text-xs font-semibold tabular-nums">{money(r.balance)}</span>
                    </div>
                    <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                        <div className="h-full rounded-full transition-all duration-700 ease-out" style={{ backgroundColor: accent, width: `${(r.balance / max) * 100}%` }} />
                    </div>
                </div>
            ))}
        </div>
    );
}

function TopItems({ items, currency }: { items: TopItem[]; currency: string }) {
    if (items.length === 0) {
        return <p className="text-muted-foreground py-6 text-center text-sm">No stock on hand.</p>;
    }
    const max = Math.max(1, ...items.map((i) => i.value));
    return (
        <div className="space-y-3">
            {items.map((it) => (
                <div key={it.code}>
                    <div className="mb-1 flex items-center justify-between text-sm">
                        <span className="truncate"><span className="font-mono text-xs text-muted-foreground">{it.code}</span> {it.name}</span>
                        <span className="ml-2 shrink-0 font-mono text-xs font-semibold tabular-nums">{money(it.value)}</span>
                    </div>
                    <div className="bg-muted h-2 overflow-hidden rounded-full">
                        <div className="h-full rounded-full transition-all duration-700 ease-out" style={{ ...gradient('#f59e0b', '#c2410c'), width: `${(it.value / max) * 100}%` }} />
                    </div>
                </div>
            ))}
        </div>
    );
}
