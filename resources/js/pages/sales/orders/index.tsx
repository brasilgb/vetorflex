import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import SaleStatusBadge from '@/components/sales/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { dashboard } from '@/routes/sales';
import { create, exportMethod, index, show } from '@/routes/sales/orders';
import type { NamedRecord, Option, Paginated, SaleOrderSummary } from '@/types';

type Filters = Partial<
    Record<
        | 'search'
        | 'customer'
        | 'seller'
        | 'status'
        | 'product'
        | 'origin'
        | 'payment_method'
        | 'from'
        | 'until',
        string | null
    >
>;

export default function SaleOrders({
    orders,
    filters,
    statuses,
    origins,
    methods,
    sellers,
    selectedCustomer,
    selectedProduct,
}: {
    orders: Paginated<SaleOrderSummary>;
    filters: Filters;
    statuses: Option[];
    origins: Option[];
    methods: Option[];
    sellers: NamedRecord[];
    selectedCustomer: NamedRecord | null;
    selectedProduct: NamedRecord | null;
}) {
    const { can } = useTenant();
    const form = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        origin: filters.origin ?? '',
        payment_method: filters.payment_method ?? '',
        seller: filters.seller ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
        customer: filters.customer ?? '',
        product: filters.product ?? '',
    });
    const { data, setData } = form;
    const query = Object.fromEntries(
        Object.entries(data).filter(([, value]) => value !== ''),
    );
    const select = (
        field: 'status' | 'origin' | 'payment_method' | 'seller',
        label: string,
        options: { value: string; label: string }[],
    ) => (
        <NativeSelect
            aria-label={label}
            value={data[field]}
            onChange={(event) => setData(field, event.target.value)}
        >
            <option value="">{label}: todos</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </NativeSelect>
    );

    return (
        <>
            <Head title="Vendas" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Pedidos de venda"
                        description="Vendas diretas e convertidas de orçamentos."
                    />
                    <div className="flex flex-wrap gap-2">
                        {can('sales.export') && (
                            <Button variant="outline" asChild>
                                <a href={exportMethod.url({ query })}>
                                    Exportar CSV
                                </a>
                            </Button>
                        )}
                        {can('sales.create') && (
                            <Button asChild>
                                <Link href={create({ query: { quick: 1 } })}>
                                    Nova venda
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
                <form
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        className="sm:col-span-2"
                        type="search"
                        aria-label="Pesquisar"
                        placeholder="Número, cliente ou CPF/CNPJ"
                        value={data.search}
                        onChange={(event) =>
                            setData('search', event.target.value)
                        }
                    />
                    {select('status', 'Situação', statuses)}
                    {select('origin', 'Origem', origins)}
                    {select('payment_method', 'Forma de pagamento', methods)}
                    {select(
                        'seller',
                        'Responsável',
                        sellers.map((seller) => ({
                            value: String(seller.id),
                            label: seller.name,
                        })),
                    )}
                    <Input
                        type="date"
                        aria-label="Vendas a partir de"
                        value={data.from}
                        onChange={(event) =>
                            setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Vendas até"
                        value={data.until}
                        onChange={(event) =>
                            setData('until', event.target.value)
                        }
                    />
                    {(selectedCustomer || selectedProduct) && (
                        <p className="text-sm text-muted-foreground sm:col-span-2 lg:col-span-4">
                            {selectedCustomer &&
                                `Cliente: ${selectedCustomer.name}. `}
                            {selectedProduct &&
                                `Item: ${selectedProduct.name}.`}
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Button variant="outline" disabled={form.processing}>
                            Filtrar
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()} preserveState={false}>
                                Limpar
                            </Link>
                        </Button>
                    </div>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Venda</th>
                                <th className="p-3">Cliente</th>
                                <th className="p-3">Data</th>
                                <th className="p-3">Responsável</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={6}
                                    >
                                        Nenhuma venda encontrada.
                                    </td>
                                </tr>
                            )}
                            {orders.data.map((order) => (
                                <tr key={order.id} className="border-t">
                                    <td className="p-3">
                                        <Link
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={show(order.id)}
                                        >
                                            #{order.number}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {order.is_quick
                                                ? 'Venda rápida'
                                                : order.origin.label}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {order.customer ??
                                            'Consumidor não identificado'}
                                    </td>
                                    <td className="p-3">
                                        {formatDate(order.order_date)}
                                    </td>
                                    <td className="p-3">
                                        {order.seller_name ?? '—'}
                                    </td>
                                    <td className="p-3">
                                        <SaleStatusBadge
                                            status={order.status}
                                        />
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(order.total)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={orders.links} />
            </div>
        </>
    );
}

SaleOrders.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Pedidos de venda', href: index() },
    ],
};
