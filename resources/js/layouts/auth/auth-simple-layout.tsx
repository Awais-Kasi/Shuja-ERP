import { Link } from '@inertiajs/react';
import { ArrowUpRight, Boxes, Building2, Coins, Factory, Landmark, Receipt, ShieldCheck, ShoppingCart, Sparkles, Target, Truck, Users, Wallet } from 'lucide-react';
import { useLayoutEffect } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

// Every core module — shown as an animated grid on wide screens and a scrolling marquee everywhere.
const MODULES = [
    { icon: Landmark, label: 'Accounting' },
    { icon: Boxes, label: 'Inventory' },
    { icon: ShoppingCart, label: 'Purchasing' },
    { icon: Receipt, label: 'Sales' },
    { icon: Factory, label: 'Manufacturing' },
    { icon: Truck, label: 'Consignment' },
    { icon: Wallet, label: 'Banking' },
    { icon: Target, label: 'Budgeting' },
    { icon: Building2, label: 'Fixed Assets' },
    { icon: Users, label: 'Payroll' },
    { icon: Coins, label: 'Multi-currency' },
];

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    // Auth screens are a committed light experience — drop the app's dark class while
    // mounted (restoring it on exit) so every control renders in the light palette.
    useLayoutEffect(() => {
        const root = document.documentElement;
        const wasDark = root.classList.contains('dark');
        if (wasDark) {
            root.classList.remove('dark');
        }
        return () => {
            if (wasDark) {
                root.classList.add('dark');
            }
        };
    }, []);

    return (
        <div className="auth-scope relative min-h-svh overflow-hidden bg-slate-50 text-slate-900 lg:grid lg:grid-cols-[1.05fr_1fr]">
            <style>{`
                @keyframes authRise { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
                @keyframes authCard { from{opacity:0;transform:translateY(22px) scale(.985)} to{opacity:1;transform:translateY(0) scale(1)} }
                @keyframes authFloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-9px)} }
                @keyframes authFloat2 { 0%,100%{transform:translateY(0)} 50%{transform:translateY(7px)} }
                @keyframes authDrift { 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(24px,-18px) scale(1.08)} }
                @keyframes authDrift2 { 0%,100%{transform:translate(0,0) scale(1)} 50%{transform:translate(-22px,16px) scale(1.1)} }
                @keyframes authGradient { to{background-position:200% center} }
                @keyframes authBar { from{transform:scaleY(0)} to{transform:scaleY(1)} }
                @keyframes authSheen { 0%{transform:translateX(-130%)} 60%,100%{transform:translateX(230%)} }
                @keyframes authRing { 0%,100%{opacity:.55;transform:scale(1)} 50%{opacity:.9;transform:scale(1.06)} }
                @keyframes authMarquee { from{transform:translateX(0)} to{transform:translateX(-50%)} }
                @keyframes authPop { from{opacity:0;transform:translateY(10px) scale(.9)} to{opacity:1;transform:translateY(0) scale(1)} }
                .auth-marquee-mask{ -webkit-mask-image:linear-gradient(90deg,transparent,#000 12%,#000 88%,transparent); mask-image:linear-gradient(90deg,transparent,#000 12%,#000 88%,transparent); }
                .auth-marquee{ display:flex; width:max-content; animation:authMarquee 30s linear infinite; }
                .auth-marquee:hover{ animation-play-state:paused; }
                .auth-tile{ transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
                .auth-tile:hover{ transform:translateY(-3px); box-shadow:0 12px 22px -12px rgba(79,70,229,.45); border-color:#c7d2fe; }
                .auth-grad-text{ background-image:linear-gradient(90deg,#4f46e5,#7c3aed,#c026d3,#7c3aed,#4f46e5); background-size:200% auto; -webkit-background-clip:text; background-clip:text; color:transparent; animation:authGradient 6s linear infinite; }
                .auth-btn{ position:relative; overflow:hidden; background-size:180% auto; animation:authGradient 5s linear infinite; box-shadow:0 12px 26px -10px rgba(79,70,229,.65); }
                .auth-btn .sheen{ position:absolute; top:0; bottom:0; left:0; width:45%; background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent); transform:translateX(-130%); pointer-events:none; }
                .auth-btn:hover .sheen{ animation:authSheen .9s ease }
                @media (prefers-reduced-motion: reduce){ .auth-anim,.auth-card,.auth-grad-text,.auth-btn,.auth-bar,.auth-blob,.auth-ring{animation:none!important} }
            `}</style>

            {/* Animated aurora backdrop — visible at every width so the form never sits on a bare page */}
            <div className="pointer-events-none absolute inset-0 -z-0">
                <div className="auth-blob absolute -left-32 -top-40 size-[34rem] rounded-full opacity-70 blur-3xl" style={{ backgroundImage: 'radial-gradient(circle,#c7d2fe,transparent 62%)', animation: 'authDrift 22s ease-in-out infinite' }} />
                <div className="auth-blob absolute -right-40 top-1/4 size-[32rem] rounded-full opacity-60 blur-3xl" style={{ backgroundImage: 'radial-gradient(circle,#f5d0fe,transparent 62%)', animation: 'authDrift2 26s ease-in-out infinite' }} />
                <div className="auth-blob absolute bottom-[-12rem] left-1/3 size-[30rem] rounded-full opacity-50 blur-3xl" style={{ backgroundImage: 'radial-gradient(circle,#bae6fd,transparent 62%)', animation: 'authDrift 30s ease-in-out infinite reverse' }} />
                <div className="absolute inset-0 opacity-[0.4]" style={{ backgroundImage: 'radial-gradient(circle at 1px 1px, rgba(15,23,42,0.06) 1px, transparent 0)', backgroundSize: '28px 28px', maskImage: 'radial-gradient(ellipse at center, black, transparent 78%)' }} />
            </div>

            {/* Brand panel — enriches wide screens */}
            <div className="relative z-10 hidden flex-col justify-between border-r border-white/60 p-12 lg:flex xl:p-16">
                <div className="auth-anim flex items-center gap-3" style={{ animation: 'authRise .6s ease-out both' }}>
                    <div className="flex size-11 items-center justify-center rounded-2xl text-white shadow-md" style={{ backgroundImage: 'linear-gradient(135deg,#4f46e5,#7c3aed)' }}>
                        <AppLogoIcon className="size-6 fill-current text-white" />
                    </div>
                    <div>
                        <div className="text-lg font-semibold leading-none tracking-tight text-slate-900">Shuja ERP</div>
                        <div className="text-xs text-slate-500">Enterprise Resource Planning</div>
                    </div>
                </div>

                <div>
                    <div className="auth-anim mb-4 inline-flex items-center gap-2 rounded-full border border-indigo-100 bg-white/70 px-3 py-1 text-xs font-medium text-indigo-600 shadow-sm backdrop-blur" style={{ animation: 'authRise .6s ease-out .06s both' }}>
                        <Sparkles className="size-3.5" /> One connected system
                    </div>
                    <h2 className="auth-anim max-w-md text-[2.1rem] font-semibold leading-[1.12] tracking-tight text-slate-900 xl:text-[2.7rem]" style={{ animation: 'authRise .6s ease-out .12s both' }}>
                        Run your business on
                        <br />
                        <span className="auth-grad-text">one connected ledger.</span>
                    </h2>
                    <p className="auth-anim mt-4 max-w-md text-[15px] leading-relaxed text-slate-500" style={{ animation: 'authRise .6s ease-out .2s both' }}>
                        Purchase, manufacturing, sales, consignment, payroll and fixed assets — every transaction flows into one real-time double-entry core.
                    </p>

                    <div className="auth-anim relative mt-8 max-w-sm" style={{ animation: 'authRise .7s ease-out .3s both' }}>
                        <div className="rounded-2xl border border-slate-200/80 bg-white/80 p-4 shadow-xl shadow-indigo-500/10 backdrop-blur" style={{ animation: 'authFloat 6s ease-in-out infinite' }}>
                            <div className="mb-3 flex items-center justify-between">
                                <div className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-rose-400" /><span className="size-2 rounded-full bg-amber-400" /><span className="size-2 rounded-full bg-emerald-400" /></div>
                                <span className="text-[10px] font-medium uppercase tracking-wide text-slate-400">Overview</span>
                            </div>
                            <div className="grid grid-cols-2 gap-2.5">
                                <div className="rounded-xl p-3 text-white" style={{ backgroundImage: 'linear-gradient(135deg,#10b981,#0f766e)' }}>
                                    <div className="text-[10px] uppercase tracking-wide text-white/80">Liquid</div>
                                    <div className="mt-0.5 font-mono text-sm font-bold">1,990,000</div>
                                </div>
                                <div className="rounded-xl p-3 text-white" style={{ backgroundImage: 'linear-gradient(135deg,#6366f1,#4338ca)' }}>
                                    <div className="text-[10px] uppercase tracking-wide text-white/80">Net Position</div>
                                    <div className="mt-0.5 font-mono text-sm font-bold">3,987,120</div>
                                </div>
                            </div>
                            <div className="mt-3 flex h-16 items-end gap-1.5">
                                {[42, 58, 35, 70, 52, 88, 64].map((h, i) => (
                                    <div key={i} className="auth-bar flex-1 origin-bottom rounded-t" style={{ height: `${h}%`, backgroundImage: 'linear-gradient(180deg,#818cf8,#4338ca)', animation: `authBar .7s ease-out ${0.5 + i * 0.07}s both` }} />
                                ))}
                            </div>
                        </div>
                        <div className="absolute -right-5 -top-5 rounded-xl border border-slate-200/80 bg-white/90 px-3 py-2 shadow-lg backdrop-blur" style={{ animation: 'authFloat2 7s ease-in-out infinite' }}>
                            <div className="flex items-center gap-1.5 text-xs font-semibold text-emerald-600"><ArrowUpRight className="size-3.5" /> Balanced</div>
                            <div className="text-[10px] text-slate-400">Ledger in sync</div>
                        </div>
                    </div>

                    <div className="mt-8">
                        <div className="auth-anim mb-3 text-xs font-medium uppercase tracking-widest text-slate-400" style={{ animation: 'authRise .5s ease-out .42s both' }}>Every operation, one platform</div>
                        <div className="grid max-w-md grid-cols-3 gap-2">
                            {MODULES.map((m, i) => (
                                <div key={m.label} className="auth-tile flex items-center gap-2 rounded-xl border border-slate-200 bg-white/70 px-2.5 py-2 text-xs font-medium text-slate-600 shadow-sm backdrop-blur" style={{ animation: `authPop .45s ease-out ${0.5 + i * 0.05}s both` }}>
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-lg text-white" style={{ backgroundImage: 'linear-gradient(135deg,#6366f1,#7c3aed)' }}><m.icon className="size-3.5" /></span>
                                    <span className="truncate">{m.label}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="auth-anim flex items-center gap-2 text-xs text-slate-400" style={{ animation: 'authRise .6s ease-out .7s both' }}>
                    <ShieldCheck className="size-4 text-slate-400" /> Bank-grade double-entry integrity · Multi-tenant · Role-based access
                </div>
            </div>

            {/* Form panel — a floating glass card, elegant at every width */}
            <div className="relative z-10 flex min-h-svh flex-col items-center justify-center gap-5 p-4 sm:p-6 md:p-10">
                <div className="auth-card w-full max-w-md rounded-3xl border border-white/70 bg-white/80 p-6 shadow-[0_30px_80px_-30px_rgba(49,46,129,0.45)] backdrop-blur-xl sm:p-8" style={{ animation: 'authCard .6s cubic-bezier(.16,.84,.44,1) both' }}>
                    <div className="flex flex-col items-center gap-4">
                        <Link href={home()} className="relative flex flex-col items-center gap-2 font-medium">
                            <span className="auth-ring absolute -inset-2 rounded-2xl opacity-60 blur-lg" style={{ backgroundImage: 'linear-gradient(135deg,#818cf8,#c084fc)', animation: 'authRing 4s ease-in-out infinite' }} />
                            <div className="relative flex size-12 items-center justify-center rounded-2xl text-white shadow-lg" style={{ backgroundImage: 'linear-gradient(135deg,#4f46e5,#7c3aed)', animation: 'authFloat 5s ease-in-out infinite' }}>
                                <AppLogoIcon className="size-7 fill-current text-white" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-1.5 text-center">
                            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
                            <p className="text-sm text-slate-500">{description}</p>
                        </div>
                    </div>

                    <div className="mt-8">{children}</div>

                    <p className="mt-8 text-center text-xs text-slate-400">© {new Date().getFullYear()} Shuja Industries — Shuja ERP</p>
                </div>

                {/* Animated core-modules marquee — visible at every width */}
                <div className="auth-anim w-full max-w-md" style={{ animation: 'authRise .6s ease-out .5s both' }}>
                    <div className="auth-marquee-mask overflow-hidden">
                        <div className="auth-marquee py-1">
                            {[...MODULES, ...MODULES].map((m, i) => (
                                <span key={i} className="mx-1 inline-flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white/70 px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm backdrop-blur">
                                    <m.icon className="size-3.5 text-indigo-500" /> {m.label}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
