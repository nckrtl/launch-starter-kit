import { Link } from "@inertiajs/react";
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from "@/components/ui/sidebar";
import { useCurrentUrl } from "@/hooks/use-current-url";
import type { NavItem } from "@/lib/types";

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();
    const { isMobile, setOpenMobile } = useSidebar();

    return (
        <SidebarMenu className="mt-3.5 gap-0.5">
            {items.map((item) => (
                <SidebarMenuItem key={item.title}>
                    <SidebarMenuButton
                        render={
                            <Link
                                href={item.href}
                                prefetch
                                onClick={() => {
                                    if (isMobile) setOpenMobile(false);
                                }}
                            />
                        }
                        isActive={isCurrentUrl(item.href, undefined, item.title !== "Dashboard")}
                        className="h-11 px-3 md:h-[34px] md:px-2.5"
                        tooltip={{ children: item.title }}
                    >
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            ))}
        </SidebarMenu>
    );
}
