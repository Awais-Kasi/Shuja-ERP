import { usePage } from '@inertiajs/react';
import { Compass, LifeBuoy, PlayCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { helpForPath } from '@/lib/help-content';

/** Event names used to open the help modal, start the tour, or reopen the welcome card. */
export const OPEN_HELP_EVENT = 'erp-open-help';
export const START_TOUR_EVENT = 'erp-start-tour';
export const OPEN_WELCOME_EVENT = 'erp-open-welcome';

export function openHelp() {
    window.dispatchEvent(new Event(OPEN_HELP_EVENT));
}

/**
 * "Madad" centre — a friendly, plain-Urdu help modal that shows guidance for the
 * screen the user is currently on. Mounted once in the app layout; opened from the
 * header button (or anywhere via openHelp()).
 */
export function HelpCenter() {
    const [open, setOpen] = useState(false);
    const page = usePage();
    const entry = helpForPath(page.url ?? '/');

    useEffect(() => {
        const show = () => setOpen(true);
        window.addEventListener(OPEN_HELP_EVENT, show);
        return () => window.removeEventListener(OPEN_HELP_EVENT, show);
    }, []);

    const startTour = () => {
        setOpen(false);
        window.dispatchEvent(new Event(START_TOUR_EVENT));
    };
    const openWelcome = () => {
        setOpen(false);
        window.dispatchEvent(new Event(OPEN_WELCOME_EVENT));
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="max-h-[85vh] gap-0 overflow-y-auto p-0 sm:max-w-lg">
                <DialogHeader className="rounded-t-lg bg-gradient-to-br from-indigo-600 to-violet-700 p-5 text-left text-white">
                    <DialogTitle className="flex items-center gap-2 text-xl text-white">
                        <LifeBuoy className="size-5" /> Madad
                    </DialogTitle>
                    <p className="mt-1 text-lg font-semibold">{entry.title}</p>
                    <p className="text-sm text-indigo-100">{entry.intro}</p>
                </DialogHeader>

                <div className="p-5">
                    <ol className="space-y-3">
                        {entry.steps.map((s, i) => (
                            <li key={i} className="flex gap-3">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                    {i + 1}
                                </span>
                                <div className="pt-0.5">
                                    <p className="text-sm leading-relaxed">
                                        {s.text}
                                    </p>
                                    {s.note && (
                                        <p className="text-muted-foreground mt-0.5 text-xs">
                                            {s.note}
                                        </p>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>

                    <div className="mt-6 grid gap-2 border-t pt-4 sm:grid-cols-2">
                        <button
                            type="button"
                            onClick={startTour}
                            className="flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-3 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                        >
                            <PlayCircle className="size-4" /> App ka Tour dekhein
                        </button>
                        <button
                            type="button"
                            onClick={openWelcome}
                            className="flex items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-semibold transition-colors hover:bg-muted"
                        >
                            <Compass className="size-4" /> Shuruaati madad
                        </button>
                    </div>

                    <p className="text-muted-foreground mt-4 text-center text-xs">
                        Phir bhi masla ho to apne software wale se raabta karein.
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
