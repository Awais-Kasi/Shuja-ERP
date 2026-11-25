import { Head, usePage } from '@inertiajs/react';
import {
    BookOpenCheck,
    CalendarRange,
    HandCoins,
    PackageCheck,
    Printer,
    Search,
    Wallet,
    X,
    Zap,
    type LucideIcon,
} from 'lucide-react';

type Task = {
    icon: LucideIcon;
    color: string;
    title: string;
    steps: string[];
};

const TASKS: Task[] = [
    {
        icon: Zap,
        color: '#0f766e',
        title: 'Nai Bikri (Maal bechna)',
        steps: [
            'Home par "Nai Bikri" dabayein.',
            'Customer chunein (ya "Cash" rehne dein).',
            'Maal aur kitna becha likhein — rate khud aata hai.',
            'Paisa mil gaya to "Paisa mila" mein likhein, warna udhaar.',
            '"Bikri pakki karein" — ho gaya!',
        ],
    },
    {
        icon: PackageCheck,
        color: '#2563eb',
        title: 'Nai Khareed (Maal khareedna)',
        steps: [
            'Home par "Nai Khareed" dabayein.',
            'Supplier aur maal chunein, rate likhein.',
            'Paisa de diya to "Paisa diya" mein likhein, warna udhaar.',
            '"Khareed pakki karein".',
        ],
    },
    {
        icon: HandCoins,
        color: '#6d28d9',
        title: 'Paisa Aaya / Diya',
        steps: [
            'Customer se paisa aaye → "Paisa Aaya".',
            'Supplier ko paisa dein → "Paisa Diya".',
            'Raqam aur Cash/Bank chunein, save karein.',
            'Udhaar khud kam ho jayega.',
        ],
    },
    {
        icon: Wallet,
        color: '#1e3a8a',
        title: 'Udhaar (Lena / Dena)',
        steps: [
            'Menu se "Udhaar" kholein.',
            'Baayein: har customer se kitna LENA hai.',
            'Daayein: har supplier ko kitna DENA hai.',
            'Phone wale ko WhatsApp se yaad-dehani bhej sakte hain.',
        ],
    },
    {
        icon: CalendarRange,
        color: '#c2410c',
        title: 'Aaj ka Hisaab',
        steps: [
            'Menu se "Aaj ka Hisaab" kholein.',
            'Aaj kitni bikri, kitna kharcha, kitna cash aaya/gaya.',
            'Date badal kar kisi bhi din ka hisaab dekhein.',
        ],
    },
];

/**
 * Standalone, print-ready quick-start handout in plain Urdu. Opened in a new tab
 * (from Madad → "Guide chaapें"); the client can print it and pin it near the PC.
 * Registered as a null-layout page in app.tsx so it prints with no app chrome.
 */
export default function Guide() {
    const { tenant } = usePage().props;
    const company = tenant?.current?.name ?? 'Shuja ERP';

    return (
        <>
            <Head title="Quick Start Guide" />
            <style>{`
                @page { size: A4; margin: 12mm; }
                @media print {
                    .no-print { display: none !important; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            `}</style>

            <div className="min-h-screen bg-slate-100 px-4 py-6 text-slate-900 print:bg-white print:p-0">
                {/* Top bar — not printed */}
                <div className="no-print mx-auto mb-4 flex max-w-4xl items-center justify-between">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
                    >
                        <Printer className="size-4" /> Chaapein / Print
                    </button>
                    <button
                        type="button"
                        onClick={() => window.close()}
                        className="flex items-center gap-2 rounded-lg border bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                    >
                        <X className="size-4" /> Band karein
                    </button>
                </div>

                {/* The printable sheet */}
                <div className="mx-auto max-w-4xl rounded-2xl bg-white p-8 shadow-sm print:rounded-none print:p-0 print:shadow-none">
                    {/* Header */}
                    <div className="flex items-start justify-between border-b-2 border-indigo-600 pb-4">
                        <div>
                            <div className="flex items-center gap-2 text-sm font-bold tracking-wide text-indigo-700 uppercase">
                                <BookOpenCheck className="size-5" /> {company}
                            </div>
                            <h1 className="mt-1 text-3xl font-extrabold text-slate-900">
                                Software chalane ka aasaan tareeqa
                            </h1>
                            <p className="mt-1 text-sm text-slate-600">
                                Rozana ke 5 kaam — har kaam 4–5 aasaan qadmon mein.
                                Yeh safha computer ke paas laga lein.
                            </p>
                        </div>
                    </div>

                    {/* Tasks */}
                    <div className="mt-6 grid gap-4 sm:grid-cols-2">
                        {TASKS.map((t, i) => (
                            <div
                                key={t.title}
                                className="break-inside-avoid rounded-xl border border-slate-200 p-4"
                            >
                                <div className="mb-2 flex items-center gap-3">
                                    <span
                                        className="flex size-10 shrink-0 items-center justify-center rounded-lg text-white"
                                        style={{ backgroundColor: t.color }}
                                    >
                                        <t.icon className="size-5" />
                                    </span>
                                    <div>
                                        <div className="text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                            Kaam {i + 1}
                                        </div>
                                        <h2 className="text-base leading-tight font-bold text-slate-900">
                                            {t.title}
                                        </h2>
                                    </div>
                                </div>
                                <ol className="space-y-1">
                                    {t.steps.map((s, j) => (
                                        <li
                                            key={j}
                                            className="flex gap-2 text-sm text-slate-700"
                                        >
                                            <span
                                                className="font-bold"
                                                style={{ color: t.color }}
                                            >
                                                {j + 1}.
                                            </span>
                                            <span>{s}</span>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        ))}

                        {/* Remember box fills the 6th cell */}
                        <div className="break-inside-avoid rounded-xl border-2 border-dashed border-indigo-300 bg-indigo-50 p-4">
                            <h2 className="mb-2 text-base font-bold text-indigo-800">
                                Yeh hamesha yaad rakhein
                            </h2>
                            <ul className="space-y-2 text-sm text-indigo-900">
                                <li className="flex gap-2">
                                    <Search className="mt-0.5 size-4 shrink-0 text-indigo-500" />
                                    <span>
                                        <b>Dhoondo</b> — upar search mein customer,
                                        maal ya bill ka naam likhein, seedha khul
                                        jayega.
                                    </span>
                                </li>
                                <li className="flex gap-2">
                                    <HandCoins className="mt-0.5 size-4 shrink-0 text-indigo-500" />
                                    <span>
                                        Kahin phans jayein to har screen par{' '}
                                        <b>❓ Madad</b> button dabayein — usi page
                                        ki madad mil jayegi.
                                    </span>
                                </li>
                                <li className="flex gap-2">
                                    <BookOpenCheck className="mt-0.5 size-4 shrink-0 text-indigo-500" />
                                    <span>
                                        App ko <b>Aasaan</b> mode par rakhein —
                                        sirf zaroori cheezein dikhti hain.
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <p className="mt-6 border-t pt-3 text-center text-xs text-slate-400">
                        {company} — hisaab-kitaab software · Madad: har screen par
                        "❓ Madad" button
                    </p>
                </div>
            </div>
        </>
    );
}
