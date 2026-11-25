import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { GuidedTour } from '@/components/guided-tour';
import { HelpCenter } from '@/components/help-center';
import { PrintHeader } from '@/components/print-header';
import { WelcomeGuide } from '@/components/welcome-guide';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* Company letterhead — appears only on printouts / PDFs, on every screen. */}
                <div className="px-4 md:px-6">
                    <PrintHeader />
                </div>
                {children}
            </AppContent>

            {/* Non-technical-user helpers (render once, globally). */}
            <WelcomeGuide />
            <GuidedTour />
            <HelpCenter />
        </AppShell>
    );
}
