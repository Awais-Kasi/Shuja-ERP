import { Info } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * A friendly one-line hint shown at the top of a screen, in plain local language,
 * so a non-accountant user knows what the screen is for. Hidden when printing.
 */
export function ScreenHelp({ children }: { children: ReactNode }) {
    return (
        <div className="no-print mb-4 flex items-start gap-2 rounded-xl border border-indigo-200/70 bg-indigo-50 px-3 py-2 text-sm text-indigo-900 dark:border-indigo-900/50 dark:bg-indigo-950/40 dark:text-indigo-200">
            <Info className="mt-0.5 size-4 shrink-0 text-indigo-500" />
            <p className="leading-relaxed">{children}</p>
        </div>
    );
}
