import { Link, router, usePage } from '@inertiajs/react';
import {
    KeyRound,
    LayoutGrid,
    Package,
    Store,
    Receipt,
    Warehouse,
    ShieldCheck,
    ShoppingCart,
    UsersRound,
    Wallet,
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
import {
    cashFlow,
    dashboard as finance,
    settings as financeSettings,
} from '@/routes/finance';
import { index as financeAccounts } from '@/routes/finance/accounts';
import { index as financeMovements } from '@/routes/finance/movements';
import { index as reconciliation } from '@/routes/finance/reconciliation';
import { index as recurrences } from '@/routes/finance/recurrences';
import { index as financeReports } from '@/routes/finance/reports';
import { index as financeTitles } from '@/routes/finance/titles';
import { index as financeTransfers } from '@/routes/finance/transfers';
import {
    dashboard as inventoryDashboard,
    settings as inventorySettings,
} from '@/routes/inventory';
import { index as inventoryCounts } from '@/routes/inventory/counts';
import { index as inventoryItems } from '@/routes/inventory/items';
import { index as inventoryMovements } from '@/routes/inventory/movements';
import { index as inventoryReports } from '@/routes/inventory/reports';
import { index as inventoryTransfers } from '@/routes/inventory/transfers';
import { index as inventoryWarehouses } from '@/routes/inventory/warehouses';
import { home as posHome, settings as posSettings } from '@/routes/pos';
import { index as posReports } from '@/routes/pos/reports';
import { index as posSales } from '@/routes/pos/sales';
import { index as posSessions } from '@/routes/pos/sessions';
import { index as posTerminals } from '@/routes/pos/terminals';
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
import { dashboard as sales, settings as salesSettings } from '@/routes/sales';
import { index as fulfillments } from '@/routes/sales/fulfillments';
import {
    create as createSale,
    index as saleOrders,
} from '@/routes/sales/orders';
import { create as createQuote, index as quotes } from '@/routes/sales/quotes';
import { index as saleReturns } from '@/routes/sales/returns';
import {
    home as rootAdminHome,
    settings as platformSettings,
} from '@/routes/root-admin';
import { index as administrators } from '@/routes/root-admin/administrators';
import { index as auditLogs } from '@/routes/root-admin/audit';
import { index as companies } from '@/routes/root-admin/companies';
import { index as modules } from '@/routes/root-admin/modules';
import { index as platformUsers } from '@/routes/root-admin/users';
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
                          ...(tenant.can('sales.manage_settings')
                              ? [
                                    {
                                        title: 'Configurações',
                                        href: salesSettings(),
                                    },
                                ]
                              : []),
                      ],
                  },
              ]
            : []),
        ...(tenant.can('inventory.view')
            ? [
                  {
                      title: 'Estoque',
                      href: inventoryDashboard(),
                      icon: Warehouse,
                      items: [
                          {
                              permission: 'inventory.view',
                              title: 'Visão geral',
                              href: inventoryDashboard(),
                          },
                          {
                              permission: 'inventory.view',
                              title: 'Mercadorias',
                              href: inventoryItems(),
                          },
                          {
                              permission: 'inventory.view',
                              title: 'Movimentações',
                              href: inventoryMovements(),
                          },
                          {
                              permission: 'inventory.view',
                              title: 'Transferências',
                              href: inventoryTransfers(),
                          },
                          {
                              permission: 'inventory.view',
                              title: 'Depósitos',
                              href: inventoryWarehouses(),
                          },
                          {
                              permission: 'inventory.view',
                              title: 'Inventários',
                              href: inventoryCounts(),
                          },
                          {
                              permission: 'inventory.view_reports',
                              title: 'Relatórios',
                              href: inventoryReports(),
                          },
                          {
                              permission: 'inventory.manage_settings',
                              title: 'Configurações',
                              href: inventorySettings(),
                          },
                      ]
                          .filter(({ permission }) => tenant.can(permission))
                          .map(({ title, href }) => ({ title, href })),
                  },
              ]
            : []),
        ...(tenant.can('pos.operate')
            ? [
                  {
                      title: 'PDV',
                      href: posHome(),
                      icon: Store,
                      items: [
                          {
                              permission: 'pos.operate',
                              title: 'Frente de caixa',
                              href: posHome(),
                          },
                          {
                              permission: 'pos.view_sessions',
                              title: 'Sessões de caixa',
                              href: posSessions(),
                          },
                          {
                              permission: 'pos.view_sessions',
                              title: 'Vendas do caixa',
                              href: posSales(),
                          },
                          {
                              permission: 'pos.view_reports',
                              title: 'Relatórios',
                              href: posReports(),
                          },
                          {
                              permission: 'pos.manage_terminals',
                              title: 'Terminais',
                              href: posTerminals(),
                          },
                          {
                              permission: 'pos.manage_settings',
                              title: 'Configurações',
                              href: posSettings(),
                          },
                      ]
                          .filter(({ permission }) => tenant.can(permission))
                          .map(({ title, href }) => ({ title, href })),
                  },
              ]
            : []),
        ...(tenant.hasModule('finance')
            ? [
                  {
                      title: 'Financeiro',
                      href: finance(),
                      icon: Wallet,
                      items: [
                          {
                              permission: 'finance.view_dashboard',
                              title: 'Visão geral',
                              href: finance(),
                          },
                          {
                              permission: 'finance.view_receivables',
                              title: 'Contas a receber',
                              href: financeTitles('receivables'),
                          },
                          {
                              permission: 'finance.view_payables',
                              title: 'Contas a pagar',
                              href: financeTitles('payables'),
                          },
                          {
                              permission: 'finance.view_accounts',
                              title: 'Movimentações',
                              href: financeMovements(),
                          },
                          {
                              permission: 'finance.view_accounts',
                              title: 'Contas financeiras',
                              href: financeAccounts(),
                          },
                          {
                              permission: 'finance.view_accounts',
                              title: 'Transferências',
                              href: financeTransfers(),
                          },
                          {
                              permission: 'finance.view_reports',
                              title: 'Fluxo de caixa',
                              href: cashFlow(),
                          },
                          {
                              permission: 'finance.manage_recurrences',
                              title: 'Recorrências',
                              href: recurrences(),
                          },
                          {
                              permission: 'finance.reconcile',
                              title: 'Conciliação',
                              href: reconciliation(),
                          },
                          {
                              permission: 'finance.view_reports',
                              title: 'Relatórios',
                              href: financeReports(),
                          },
                          {
                              permission: 'finance.manage_categories',
                              title: 'Configurações',
                              href: financeSettings(),
                          },
                      ]
                          .filter(({ permission }) => tenant.can(permission))
                          .map(({ title, href }) => ({ title, href })),
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
                      href: rootAdminHome(),
                      icon: ShieldCheck,
                      items: [
                          { title: 'Visão geral', href: rootAdminHome() },
                          { title: 'Empresas', href: companies() },
                          { title: 'Usuários', href: platformUsers() },
                          { title: 'Catálogo de módulos', href: modules() },
                          { title: 'Administradores', href: administrators() },
                          { title: 'Auditoria', href: auditLogs() },
                          { title: 'Configurações', href: platformSettings() },
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
