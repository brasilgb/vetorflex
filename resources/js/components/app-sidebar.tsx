import { Link, router, usePage } from '@inertiajs/react';
import {
    KeyRound,
    LayoutGrid,
    Package,
    Receipt,
    ShieldCheck,
    ShoppingCart,
    UsersRound,
} from 'lucide-react';
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
import { useTenant } from '@/hooks/use-tenant';
import { dashboard } from '@/routes';
import { index as products } from '@/routes/products';
import { index as brands } from '@/routes/products/brands';
import { index as categories } from '@/routes/products/categories';
import { index as movements } from '@/routes/products/movements';
import { index as units } from '@/routes/products/units';
import { index as members } from '@/routes/company/members';
import { dashboard as purchases } from '@/routes/purchases';
import { index as purchaseOrders } from '@/routes/purchases/orders';
import { create as quickPurchase } from '@/routes/purchases/quick';
import { index as receipts } from '@/routes/purchases/receipts';
import { index as purchaseReturns } from '@/routes/purchases/returns';
import { index as suppliers } from '@/routes/purchases/suppliers';
import { dashboard as sales } from '@/routes/sales';
import { index as fulfillments } from '@/routes/sales/fulfillments';
import {
    create as createSale,
    index as saleOrders,
} from '@/routes/sales/orders';
import { create as createQuote, index as quotes } from '@/routes/sales/quotes';
import { index as saleReturns } from '@/routes/sales/returns';
import { index as companies } from '@/routes/root-admin/companies';
import { index as modules } from '@/routes/root-admin/modules';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const tenant = useTenant();
    const { auth } = usePage<{ auth: Auth }>().props;
    const mainNavItems: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        ...(tenant.hasModule('customers')
            ? [{ title: 'Clientes', href: '/customers', icon: UsersRound }]
            : []),
        ...(tenant.can('products.view')
            ? [
                  {
                      title: 'Produtos',
                      href: products(),
                      icon: Package,
                      items: [
                          { title: 'Todos os produtos', href: products() },
                          { title: 'Categorias', href: categories() },
                          { title: 'Marcas', href: brands() },
                          { title: 'Unidades de medida', href: units() },
                          ...(tenant.can('products.view_inventory')
                              ? [{ title: 'Movimentações', href: movements() }]
                              : []),
                      ],
                  },
              ]
            : []),
        ...(tenant.can('purchases.view')
            ? [
                  {
                      title: 'Compras',
                      href: purchases(),
                      icon: ShoppingCart,
                      items: [
                          { title: 'Visão geral', href: purchases() },
                          {
                              title: 'Pedidos de compra',
                              href: purchaseOrders(),
                          },
                          ...(tenant.can('purchases.create') &&
                          tenant.can('purchases.confirm') &&
                          tenant.can('purchases.receive') &&
                          tenant.can('purchases.manage_cost')
                              ? [
                                    {
                                        title: 'Nova compra',
                                        href: quickPurchase(),
                                    },
                                ]
                              : []),
                          { title: 'Recebimentos', href: receipts() },
                          { title: 'Fornecedores', href: suppliers() },
                          { title: 'Devoluções', href: purchaseReturns() },
                      ],
                  },
              ]
            : []),
        ...(tenant.can('sales.view')
            ? [
                  {
                      title: 'Vendas',
                      href: sales(),
                      icon: Receipt,
                      items: [
                          { title: 'Visão geral', href: sales() },
                          { title: 'Orçamentos', href: quotes() },
                          ...(tenant.can('sales.manage_quotes')
                              ? [
                                    {
                                        title: 'Novo orçamento',
                                        href: createQuote(),
                                    },
                                ]
                              : []),
                          { title: 'Pedidos', href: saleOrders() },
                          ...(tenant.can('sales.create')
                              ? [
                                    {
                                        title: 'Nova venda',
                                        href: createSale({
                                            query: { quick: 1 },
                                        }),
                                    },
                                ]
                              : []),
                          { title: 'Atendimentos', href: fulfillments() },
                          { title: 'Devoluções', href: saleReturns() },
                      ],
                  },
              ]
            : []),
        ...(tenant.canManageTeam && tenant.activeCompany !== null
            ? [
                  {
                      title: 'Usuários e permissões',
                      href: members(),
                      icon: KeyRound,
                  },
              ]
            : []),
        ...(auth.user?.is_root_admin === true
            ? [
                  {
                      title: 'RootAdmin',
                      href: companies(),
                      icon: ShieldCheck,
                      items: [
                          { title: 'Empresas', href: companies() },
                          { title: 'Catálogo de módulos', href: modules() },
                      ],
                  },
              ]
            : []),
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

            {tenant.companies.length > 0 && (
                <div className="px-2 pb-2">
                    <label className="sr-only" htmlFor="active-company">
                        Empresa ativa
                    </label>
                    <select
                        className="w-full rounded-md border bg-background p-2 text-sm"
                        id="active-company"
                        value={tenant.activeCompany?.id ?? ''}
                        onChange={(event) =>
                            router.post(
                                '/active-company',
                                { company_id: event.target.value },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <option value="" disabled>
                            Selecione uma empresa
                        </option>
                        {tenant.companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
