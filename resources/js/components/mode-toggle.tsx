import { Eye, Wrench } from 'lucide-react';
import { useAppMode, type AppMode } from '@/hooks/use-app-mode';

/**
 * Segmented "Aasaan (Simple) / Poora (Full)" switch for the sidebar footer.
 * Simple hides the Accountant menu and advanced items; Full shows everything.
 */
export function ModeToggle() {
    const { mode, setMode } = useAppMode();

    const btn = (value: AppMode, label: string, Icon: typeof Eye) => {
        const active = mode === value;
        return (
            <button
                type="button"
                onClick={() => setMode(value)}
                aria-pressed={active}
                className={`flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium transition-colors ${
                    active
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                }`}
            >
                <Icon className="size-3.5" />
                {label}
            </button>
        );
    };

    return (
        <div className="bg-muted/60 mx-2 mb-1 flex items-center gap-1 rounded-lg p-1 group-data-[collapsible=icon]:hidden">
            {btn('simple', 'Aasaan', Eye)}
            {btn('full', 'Poora', Wrench)}
        </div>
    );
}
