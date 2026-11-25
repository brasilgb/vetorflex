import { Link, router, usePage } from '@inertiajs/react';
import { LayoutGrid, UsersRound } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { tenant } = usePage<{ tenant: { activeCompany: { id: number; name: string } | null; companies: { id: number; name: string }[]; modules: { identifier: string; name: string }[] } }>().props;
    const mainNavItems: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        ...(tenant.modules.some((module) => module.identifier === 'customers') ? [{ title: 'Clientes', href: '/customers', icon: UsersRound }] : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {tenant.companies.length > 0 && <div className="px-2 pb-2"><label className="sr-only" htmlFor="active-company">Empresa ativa</label><select className="w-full rounded-md border bg-background p-2 text-sm" id="active-company" value={tenant.activeCompany?.id ?? ''} onChange={(event) => router.post('/active-company', { company_id: event.target.value }, { preserveScroll: true })}><option value="" disabled>Selecione uma empresa</option>{tenant.companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select></div>}

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
