import { AppContent } from "@/components/app-content";
import { AppShell } from "@/components/app-shell";
import { AppSidebar } from "@/components/app-sidebar";
import { SidebarTrigger } from "@/components/ui/sidebar";
import type { AppLayoutProps } from "@/lib/types";

export default function AppSidebarLayout({ children }: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="commander-content overflow-x-hidden">
                <header className="sticky top-0 z-30 flex min-h-14 items-center gap-3 border-b bg-background/95 px-3 backdrop-blur md:hidden">
                    <SidebarTrigger aria-label="Open navigation menu" />
                    <span className="text-sm font-semibold">Commander</span>
                </header>
                {children}
            </AppContent>
        </AppShell>
    );
}
