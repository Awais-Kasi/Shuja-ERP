import { LifeBuoy, Printer } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { GlobalSearch } from '@/components/global-search';
import { openHelp } from '@/components/help-center';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6">
            <SidebarTrigger className="-ml-1 shrink-0" />
            <div className="hidden min-w-0 md:block">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="ml-auto flex min-w-0 flex-1 items-center justify-end gap-2">
                <div data-tour="search" className="min-w-0 flex-1 sm:flex-none">
                    <GlobalSearch />
                </div>
                <div data-tour="madad" className="shrink-0">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="gap-1.5 border-indigo-300 text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/40"
                        onClick={openHelp}
                        title="Madad — is screen ki madad dekhein"
                    >
                        <LifeBuoy className="size-4" />
                        <span className="hidden sm:inline">Madad</span>
                    </Button>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0 gap-1.5"
                    onClick={() => window.print()}
                    title="Print or save this page as PDF"
                >
                    <Printer className="size-4" />
                    <span className="hidden sm:inline">Print / PDF</span>
                </Button>
            </div>
        </header>
    );
}
