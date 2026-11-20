import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Result = { type: string; label: string; sub: string | null; href: string };

const typeColor: Record<string, string> = {
    Customer: 'text-violet-600 dark:text-violet-400',
    Supplier: 'text-sky-600 dark:text-sky-400',
    Item: 'text-amber-600 dark:text-amber-400',
    Invoice: 'text-emerald-600 dark:text-emerald-400',
    Bill: 'text-rose-600 dark:text-rose-400',
};

/** Global "Dhoondo" search: type a name/code/number, jump straight to it. */
export function GlobalSearch() {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const boxRef = useRef<HTMLDivElement>(null);

    // Debounced fetch.
    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            setResults([]);
            setLoading(false);
            return;
        }
        setLoading(true);
        const t = setTimeout(() => {
            const ctrl = new AbortController();
            fetch(`/search?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: ctrl.signal,
            })
                .then((r) => (r.ok ? r.json() : { results: [] }))
                .then((d: { results: Result[] }) => {
                    setResults(d.results ?? []);
                    setLoading(false);
                })
                .catch(() => setLoading(false));
        }, 250);
        return () => clearTimeout(t);
    }, [q]);

    // Close on outside click.
    useEffect(() => {
        const onClick = (e: MouseEvent) => {
            if (boxRef.current && !boxRef.current.contains(e.target as Node))
                setOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, []);

    const go = (href: string) => {
        setOpen(false);
        setQ('');
        setResults([]);
        router.visit(href);
    };

    return (
        <div ref={boxRef} className="no-print relative w-full max-w-xs">
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                <input
                    type="text"
                    value={q}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && results[0])
                            go(results[0].href);
                        if (e.key === 'Escape') setOpen(false);
                    }}
                    placeholder="Dhoondo… (customer, item, bill)"
                    className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-lg border pr-8 pl-8 text-sm shadow-sm focus-visible:ring-2 focus-visible:outline-none"
                />
                {q && (
                    <button
                        type="button"
                        onClick={() => {
                            setQ('');
                            setResults([]);
                        }}
                        className="text-muted-foreground hover:text-foreground absolute top-1/2 right-2 -translate-y-1/2"
                    >
                        <X className="size-4" />
                    </button>
                )}
            </div>

            {open && q.trim().length >= 2 && (
                <div className="bg-popover absolute z-50 mt-1 w-full overflow-hidden rounded-xl border shadow-lg">
                    {loading && (
                        <div className="text-muted-foreground px-3 py-3 text-sm">
                            Dhoondh raha hai…
                        </div>
                    )}
                    {!loading && results.length === 0 && (
                        <div className="text-muted-foreground px-3 py-3 text-sm">
                            Kuch nahi mila.
                        </div>
                    )}
                    {!loading &&
                        results.map((r, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => go(r.href)}
                                className="hover:bg-muted flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm"
                            >
                                <span className="min-w-0 truncate">
                                    <span className="font-medium">
                                        {r.label}
                                    </span>
                                    {r.sub && (
                                        <span className="text-muted-foreground ml-1.5 font-mono text-xs">
                                            {r.sub}
                                        </span>
                                    )}
                                </span>
                                <span
                                    className={`shrink-0 text-xs font-medium ${typeColor[r.type] ?? 'text-muted-foreground'}`}
                                >
                                    {r.type}
                                </span>
                            </button>
                        ))}
                </div>
            )}
        </div>
    );
}
