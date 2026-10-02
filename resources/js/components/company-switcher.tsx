import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';

export function CompanySwitcher() {
    const { tenant } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    const current = tenant?.current;
    const companies = tenant?.companies ?? [];

    if (!current) {
        return null;
    }

    const switchTo = (companyId: number) => {
        if (companyId === current.id) {
            return;
        }
        router.post(
            '/companies/switch',
            { company_id: companyId },
            { preserveScroll: true },
        );
    };

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        >
                            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                                <Building2 className="size-4" />
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">
                                    {current.name}
                                </span>
                                <span className="text-muted-foreground truncate text-xs">
                                    {current.code ?? 'Company'} · {current.base_currency}
                                </span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                        align="start"
                        side={isMobile ? 'bottom' : state === 'collapsed' ? 'right' : 'bottom'}
                    >
                        <DropdownMenuLabel className="text-muted-foreground text-xs">
                            Companies
                        </DropdownMenuLabel>
                        {companies.map((company) => (
                            <DropdownMenuItem
                                key={company.id}
                                onClick={() => switchTo(company.id)}
                                className="gap-2"
                            >
                                <div className="bg-muted flex size-6 items-center justify-center rounded-sm">
                                    <Building2 className="size-3.5 shrink-0" />
                                </div>
                                <span className="flex-1 truncate">{company.name}</span>
                                {company.id === current.id && (
                                    <Check className="size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                        {companies.length <= 1 && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel className="text-muted-foreground text-xs font-normal">
                                    Add more companies in a later phase.
                                </DropdownMenuLabel>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
