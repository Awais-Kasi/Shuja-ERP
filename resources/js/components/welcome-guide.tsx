import { router, usePage } from '@inertiajs/react';
import {
    CalendarRange,
    HandCoins,
    LayoutGrid,
    PackageCheck,
    PlayCircle,
    Search,
    Sparkles,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { OPEN_WELCOME_EVENT, START_TOUR_EVENT } from '@/components/help-center';
import { usePermissions } from '@/hooks/use-permissions';

const SEEN_KEY = 'erp-welcome-seen';

type Task = {
    title: string;
    sub: string;
    href: string;
    icon: LucideIcon;
    from: string;
    to: string;
    permission?: string;
};

const TASKS: Task[] = [
    {
        title: 'Nai Bikri',
        sub: 'Maal bechna',
        href: '/sales/quick',
        icon: Zap,
        from: '#10b981',
        to: '#0f766e',
        permission: 'sales.invoice.post',
    },
    {
        title: 'Nai Khareed',
        sub: 'Maal khareedna',
        href: '/purchase/quick',
        icon: PackageCheck,
        from: '#0ea5e9',
        to: '#2563eb',
        permission: 'purchase.bill.post',
    },
    {
        title: 'Udhaar',
        sub: 'Lena / Dena',
        href: '/udhaar',
        icon: HandCoins,
        from: '#6366f1',
        to: '#1e3a8a',
        permission: 'sales.order.manage',
    },
    {
        title: 'Aaj ka Hisaab',
        sub: 'Roznamcha',
        href: '/day-book',
        icon: CalendarRange,
        from: '#f59e0b',
        to: '#c2410c',
        permission: 'accounting.report.view',
    },
];

/**
 * First-login welcome card for non-technical users. Greets by name, shows the daily
 * tasks as big buttons, and offers the guided tour. Shows once per browser; can be
 * reopened any time from Madad → "Shuruaati madad".
 */
export function WelcomeGuide() {
    const [open, setOpen] = useState(false);
    const { auth } = usePage().props;
    const { can } = usePermissions();
    const firstName = auth.user.name.split(' ')[0] || 'Ji';
    const tasks = TASKS.filter((t) => !t.permission || can(t.permission));

    // Auto-show once; also reopen on demand.
    useEffect(() => {
        let seen = '1';
        try {
            seen = localStorage.getItem(SEEN_KEY) ?? '';
        } catch {
            seen = '1';
        }
        if (!seen) {
            const t = setTimeout(() => setOpen(true), 600);
            return () => clearTimeout(t);
        }
    }, []);

    useEffect(() => {
        const show = () => setOpen(true);
        window.addEventListener(OPEN_WELCOME_EVENT, show);
        return () => window.removeEventListener(OPEN_WELCOME_EVENT, show);
    }, []);

    const markSeen = () => {
        try {
            localStorage.setItem(SEEN_KEY, '1');
        } catch {
            /* ignore */
        }
    };

    const close = () => {
        markSeen();
        setOpen(false);
    };

    const go = (href: string) => {
        close();
        router.visit(href);
    };

    const startTour = () => {
        close();
        window.dispatchEvent(new Event(START_TOUR_EVENT));
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) close();
            }}
        >
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-xl">
                <DialogHeader className="rounded-t-lg bg-gradient-to-br from-indigo-700 to-violet-700 p-6 text-left text-white">
                    <div className="flex items-center gap-2 text-xs font-medium tracking-widest text-indigo-200 uppercase">
                        <Sparkles className="size-4" /> Khush Aamdeed
                    </div>
                    <DialogTitle className="mt-1 text-2xl text-white">
                        Salaam {firstName}! 👋
                    </DialogTitle>
                    <p className="mt-1 text-sm text-indigo-100">
                        Yeh aap ka hisaab-kitaab ka software hai. Ghabrayein
                        nahi — rozana ke kaam bohat aasaan hain. Neeche se shuru
                        karein:
                    </p>
                </DialogHeader>

                <div className="p-6">
                    <div className="grid grid-cols-2 gap-3">
                        {tasks.map((t) => (
                            <button
                                key={t.title}
                                type="button"
                                onClick={() => go(t.href)}
                                style={{
                                    backgroundImage: `linear-gradient(135deg, ${t.from} 0%, ${t.to} 100%)`,
                                }}
                                className="group relative flex h-20 flex-col items-start justify-between overflow-hidden rounded-2xl p-3 text-left text-white shadow-md transition-all hover:-translate-y-0.5 hover:shadow-xl"
                            >
                                <t.icon className="absolute -right-2 -bottom-2 size-14 opacity-20" />
                                <div className="flex size-8 items-center justify-center rounded-lg bg-white/20 backdrop-blur">
                                    <t.icon className="size-4" />
                                </div>
                                <div className="relative">
                                    <div className="text-sm leading-tight font-bold">
                                        {t.title}
                                    </div>
                                    <div className="text-[10px] text-white/80">
                                        {t.sub}
                                    </div>
                                </div>
                            </button>
                        ))}
                    </div>

                    <div className="mt-4 flex items-start gap-2 rounded-xl border border-indigo-200/70 bg-indigo-50 px-3 py-2.5 text-sm text-indigo-900 dark:border-indigo-900/50 dark:bg-indigo-950/40 dark:text-indigo-200">
                        <Search className="mt-0.5 size-4 shrink-0 text-indigo-500" />
                        <p className="leading-relaxed">
                            Kuch dhoondna ho (customer, maal, ya bill) to upar{' '}
                            <span className="font-semibold">"Dhoondo"</span>{' '}
                            mein naam likhein. Aur kahin phans jayein to{' '}
                            <span className="font-semibold">"Madad"</span> button
                            hamesha maujood hai.
                        </p>
                    </div>

                    <div className="mt-5 grid gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            onClick={startTour}
                            className="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                        >
                            <PlayCircle className="size-4" /> Chalo, Tour dekhte
                            hain
                        </button>
                        <button
                            type="button"
                            onClick={() => go('/dashboard')}
                            className="flex items-center justify-center gap-2 rounded-lg border px-4 py-3 text-sm font-semibold transition-colors hover:bg-muted"
                        >
                            <LayoutGrid className="size-4" /> Ghar (Dashboard)
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
