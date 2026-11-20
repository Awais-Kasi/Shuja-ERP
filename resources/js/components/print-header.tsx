import { usePage } from '@inertiajs/react';

/**
 * A header that appears ONLY on the printed page / PDF (hidden on screen).
 * Put it at the top of a report so the printout shows the company, the report
 * title, and when it was printed.
 */
export function PrintHeader({
    title,
    subtitle,
}: {
    title?: string;
    subtitle?: string;
}) {
    const page = usePage().props as {
        tenant?: { current?: { name?: string; code?: string } };
    };
    const company = page.tenant?.current?.name ?? '';
    let printed = '';
    try {
        printed = new Date().toLocaleString();
    } catch {
        printed = '';
    }

    return (
        <div className="print-only mb-4 flex items-start justify-between gap-4 border-b border-black/30 pb-3">
            <div>
                {company && <div className="text-lg font-bold">{company}</div>}
                {title && (
                    <div className="text-base font-semibold">{title}</div>
                )}
                {subtitle && <div className="text-sm">{subtitle}</div>}
            </div>
            {printed && (
                <div className="text-right text-xs opacity-70">
                    Printed:
                    <br />
                    {printed}
                </div>
            )}
        </div>
    );
}
