import { Link } from "@inertiajs/react";
import { Bot, FolderKanban, LayoutDashboard, MonitorCog } from "lucide-react";
import { index as herdr } from "@/actions/App/Http/Controllers/HerdrController";
import { index as agents } from "@/actions/App/Http/Controllers/AgentController";
import { show } from "@/actions/App/Http/Controllers/HomeController";
import { index } from "@/actions/App/Http/Controllers/ProjectController";
import AppLogo from "@/components/app-logo";
import { NavFooter } from "@/components/nav-footer";
import { NavMain } from "@/components/nav-main";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import type { NavItem } from "@/lib/types";

const mainNavItems: NavItem[] = [
    {
        title: "Dashboard",
        href: show.url(),
        icon: LayoutDashboard,
    },
    {
        title: "Projects",
        href: index.url(),
        icon: FolderKanban,
    },
    {
        title: "Agents",
        href: agents.url(),
        icon: Bot,
    },
    {
        title: "Herdr",
        href: herdr.url(),
        icon: MonitorCog,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    return (
        <Sidebar collapsible="offcanvas" variant="inset">
            <SidebarHeader className="p-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" render={<Link href={show.url()} prefetch />}>
                            <AppLogo />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="px-3">
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
            </SidebarFooter>
        </Sidebar>
    );
}
