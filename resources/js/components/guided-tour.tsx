import { useCallback, useEffect, useRef, useState } from 'react';
import { START_TOUR_EVENT } from '@/components/help-center';

type TourStep = {
    selector: string;
    title: string;
    body: string;
};

/**
 * The daily-work walkthrough. Each step points at a real on-screen element; steps
 * whose element is not present (e.g. sidebar links on a closed mobile drawer) are
 * skipped automatically, so the tour is safe on any page and any screen size.
 */
const STEPS: TourStep[] = [
    {
        selector: '[data-tour="quick-actions"]',
        title: 'Jaldi Kaam',
        body: 'Rozana ke sab kaam yahin se shuru hote hain — bikri, khareed, paisa aur udhaar. Bas bada button dabayein.',
    },
    {
        selector: 'a[href="/sales/quick"]',
        title: 'Nai Bikri',
        body: 'Maal bechne ke liye yahan. Customer aur maal chunein, "Bikri pakki karein" — ho gaya.',
    },
    {
        selector: 'a[href="/purchase/quick"]',
        title: 'Nai Khareed',
        body: 'Maal khareedne ke liye yahan. Supplier aur maal chunein, bas.',
    },
    {
        selector: 'a[href="/udhaar"]',
        title: 'Udhaar — Lena / Dena',
        body: 'Kis ne aap ko kitna dena hai, aur aap ne kis ko kitna dena hai — sab ek nazar mein.',
    },
    {
        selector: 'a[href="/day-book"]',
        title: 'Aaj ka Hisaab',
        body: 'Aaj kitni bikri hui, kitna kharcha aur kitna cash aaya — ek safe par.',
    },
    {
        selector: '[data-tour="search"]',
        title: 'Dhoondo',
        body: 'Kisi customer, maal ya bill ka naam yahan likhein — seedha us ki screen khul jayegi.',
    },
    {
        selector: '[data-tour="madad"]',
        title: 'Madad',
        body: 'Kabhi samajh na aaye to yeh button dabayein. Har screen ki apni madad yahan milegi.',
    },
];

const TIP_W = 320;
const PAD = 8;

function isVisible(el: Element | null): el is HTMLElement {
    if (!el) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
}

/** First step index at or after `start` (stepping by `dir`) whose element is visible. */
function resolveFrom(start: number, dir: 1 | -1): number {
    for (let i = start; i >= 0 && i < STEPS.length; i += dir) {
        if (isVisible(document.querySelector(STEPS[i].selector))) {
            return i;
        }
    }
    return -1;
}

type Box = { top: number; left: number; width: number; height: number };
type Pos = { top: number; left: number; place: 'below' | 'above' };

export function GuidedTour() {
    const [active, setActive] = useState(false);
    const [index, setIndex] = useState(0);
    const [box, setBox] = useState<Box | null>(null);
    const [pos, setPos] = useState<Pos | null>(null);
    const tipRef = useRef<HTMLDivElement>(null);

    const reposition = useCallback((i: number) => {
        const el = document.querySelector(STEPS[i].selector);
        if (!isVisible(el)) {
            setBox(null);
            return;
        }
        const r = el.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const b: Box = {
            top: Math.max(0, r.top - PAD),
            left: Math.max(0, r.left - PAD),
            width: r.width + PAD * 2,
            height: r.height + PAD * 2,
        };
        setBox(b);

        const tipH = tipRef.current?.offsetHeight ?? 170;
        const below = b.top + b.height + 12;
        const fitsBelow = below + tipH <= vh - 12;
        const top = fitsBelow
            ? below
            : Math.max(12, b.top - tipH - 12);
        const left = Math.min(
            Math.max(12, r.left + r.width / 2 - TIP_W / 2),
            Math.max(12, vw - TIP_W - 12),
        );
        setPos({ top, left, place: fitsBelow ? 'below' : 'above' });
    }, []);

    // Start / restart on event.
    useEffect(() => {
        const start = () => {
            const first = resolveFrom(0, 1);
            if (first === -1) return;
            setIndex(first);
            setActive(true);
        };
        window.addEventListener(START_TOUR_EVENT, start);
        return () => window.removeEventListener(START_TOUR_EVENT, start);
    }, []);

    // Place the highlight whenever the step changes; settle once after layout.
    useEffect(() => {
        if (!active) return;
        const el = document.querySelector(STEPS[index].selector);
        el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        const t1 = window.setTimeout(() => reposition(index), 60);
        const t2 = window.setTimeout(() => reposition(index), 320);
        return () => {
            window.clearTimeout(t1);
            window.clearTimeout(t2);
        };
    }, [active, index, reposition]);

    // Keep it aligned on scroll / resize.
    useEffect(() => {
        if (!active) return;
        let raf = 0;
        const onMove = () => {
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => reposition(index));
        };
        window.addEventListener('scroll', onMove, true);
        window.addEventListener('resize', onMove);
        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener('scroll', onMove, true);
            window.removeEventListener('resize', onMove);
        };
    }, [active, index, reposition]);

    const close = useCallback(() => {
        setActive(false);
        setBox(null);
        setPos(null);
    }, []);

    const next = useCallback(() => {
        const ni = resolveFrom(index + 1, 1);
        if (ni === -1) close();
        else setIndex(ni);
    }, [index, close]);

    const back = useCallback(() => {
        const pi = resolveFrom(index - 1, -1);
        if (pi !== -1) setIndex(pi);
    }, [index]);

    // Esc to close, arrows to navigate.
    useEffect(() => {
        if (!active) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowRight' || e.key === 'Enter') next();
            else if (e.key === 'ArrowLeft') back();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [active, close, next, back]);

    if (!active) return null;

    const total = STEPS.length;
    const human = STEPS.filter((s) => isVisible(document.querySelector(s.selector)));
    const shownNo = human.findIndex((s) => s.selector === STEPS[index].selector) + 1;
    const isLast = resolveFrom(index + 1, 1) === -1;
    const isFirst = resolveFrom(index - 1, -1) === -1;

    return (
        <div className="no-print" style={{ position: 'fixed', inset: 0, zIndex: 1000 }}>
            {/* Click-catcher behind the spotlight; clicking the dim area advances. */}
            <div
                style={{ position: 'fixed', inset: 0 }}
                onClick={next}
                aria-hidden
            />

            {/* Spotlight hole (visual only). */}
            {box && (
                <div
                    style={{
                        position: 'fixed',
                        top: box.top,
                        left: box.left,
                        width: box.width,
                        height: box.height,
                        borderRadius: 14,
                        boxShadow:
                            '0 0 0 9999px rgba(15,23,42,0.68), 0 0 0 3px rgba(129,140,248,0.9)',
                        pointerEvents: 'none',
                        transition: 'all .2s ease',
                        zIndex: 1001,
                    }}
                />
            )}
            {/* Full dim when no target resolved yet. */}
            {!box && (
                <div
                    style={{
                        position: 'fixed',
                        inset: 0,
                        background: 'rgba(15,23,42,0.68)',
                        pointerEvents: 'none',
                        zIndex: 1001,
                    }}
                />
            )}

            {/* Tooltip */}
            <div
                ref={tipRef}
                onClick={(e) => e.stopPropagation()}
                style={{
                    position: 'fixed',
                    top: pos?.top ?? window.innerHeight / 2 - 90,
                    left: pos?.left ?? Math.max(12, window.innerWidth / 2 - TIP_W / 2),
                    width: TIP_W,
                    maxWidth: 'calc(100vw - 24px)',
                    zIndex: 1002,
                }}
                className="rounded-2xl border border-indigo-300 bg-white p-4 shadow-2xl dark:border-indigo-800 dark:bg-slate-900"
            >
                <div className="mb-1 flex items-center justify-between">
                    <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                        Qadam {shownNo > 0 ? shownNo : 1} / {human.length || total}
                    </span>
                    <button
                        type="button"
                        onClick={close}
                        className="text-muted-foreground hover:text-foreground text-xs font-medium"
                    >
                        Band karo ✕
                    </button>
                </div>
                <h3 className="text-base font-bold text-indigo-700 dark:text-indigo-300">
                    {STEPS[index].title}
                </h3>
                <p className="mt-1 text-sm leading-relaxed text-slate-700 dark:text-slate-200">
                    {STEPS[index].body}
                </p>
                <div className="mt-4 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        onClick={back}
                        disabled={isFirst}
                        className="rounded-lg border px-3 py-1.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        Peeche
                    </button>
                    <button
                        type="button"
                        onClick={next}
                        className="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                    >
                        {isLast ? 'Theek hai 👍' : 'Aage'}
                    </button>
                </div>
            </div>
        </div>
    );
}
