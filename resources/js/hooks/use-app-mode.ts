import { useCallback, useEffect, useState } from 'react';

/**
 * "Simple" vs "Full" view. Simple mode hides heavy accounting menus so daily
 * shop/factory staff see only what they need. Stored per-browser; defaults to
 * Simple. Admins can switch to Full anytime from the sidebar.
 */
export type AppMode = 'simple' | 'full';

const KEY = 'erp-app-mode';
const EVENT = 'erp-app-mode-change';

function read(): AppMode {
    try {
        return localStorage.getItem(KEY) === 'full' ? 'full' : 'simple';
    } catch {
        return 'simple';
    }
}

export function useAppMode() {
    const [mode, setModeState] = useState<AppMode>('simple');

    useEffect(() => {
        setModeState(read());
        const sync = () => setModeState(read());
        window.addEventListener(EVENT, sync);
        window.addEventListener('storage', sync);
        return () => {
            window.removeEventListener(EVENT, sync);
            window.removeEventListener('storage', sync);
        };
    }, []);

    const setMode = useCallback((m: AppMode) => {
        try {
            localStorage.setItem(KEY, m);
        } catch {
            /* ignore — private window etc. */
        }
        setModeState(m);
        window.dispatchEvent(new Event(EVENT));
    }, []);

    const toggle = useCallback(
        () => setMode(read() === 'full' ? 'simple' : 'full'),
        [setMode],
    );

    return { mode, setMode, toggle };
}
