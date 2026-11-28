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
import { create, index, show } from '@/routes/sales/quotes';
import type { NamedRecord, Option, Paginated, SaleQuoteSummary } from '@/types';

type Filters = Partial<
    Record<
        | 'search'
        | 'customer'
        | 'seller'
        | 'status'
        | 'product'
        | 'from'
        | 'until',
        string | null
    >
>;

export default function SaleQuotes({
    quotes,
    filters,
    statuses,
    sellers,
    selectedCustomer,
    selectedProduct,
}: {
    quotes: Paginated<SaleQuoteSummary>;
    filters: Filters;
    statuses: Option[];
    sellers: NamedRecord[];
    selectedCustomer: NamedRecord | null;
    selectedProduct: NamedRecord | null;
}) {
    const { can } = useTenant();
    const form = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        seller: filters.seller ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
        customer: filters.customer ?? '',
        product: filters.product ?? '',
    });
    const { data, setData } = form;

    return (
        <>
            <Head title="Orçamentos" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Orçamentos"
                        description="Propostas comerciais. Não movimentam estoque nem geram cobrança."
                    />
                    {can('sales.manage_quotes') && (
                        <Button asChild>
                            <Link href={create()}>Novo orçamento</Link>
                        </Button>
                    )}
                </div>
                <form
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
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
                    <NativeSelect
                        aria-label="Situação"
                        value={data.status}
                        onChange={(event) =>
                            setData('status', event.target.value)
                        }
                    >
                        <option value="">Todas as situações</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Responsável"
                        value={data.seller}
                        onChange={(event) =>
                            setData('seller', event.target.value)
                        }
                    >
                        <option value="">Todos os responsáveis</option>
                        {sellers.map((seller) => (
                            <option key={seller.id} value={seller.id}>
                                {seller.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label="Emitidos a partir de"
                        value={data.from}
                        onChange={(event) =>
                            setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Emitidos até"
                        value={data.until}
                        onChange={(event) =>
                            setData('until', event.target.value)
                        }
                    />
                    {(selectedCustomer || selectedProduct) && (
                        <p className="text-sm text-muted-foreground sm:col-span-2 lg:col-span-6">
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
                                <th className="p-3">Orçamento</th>
                                <th className="p-3">Cliente</th>
                                <th className="p-3">Emissão</th>
                                <th className="p-3">Validade</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {quotes.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={6}
                                    >
                                        Nenhum orçamento encontrado.
                                    </td>
                                </tr>
                            )}
                            {quotes.data.map((quote) => (
                                <tr key={quote.id} className="border-t">
                                    <td className="p-3">
                                        <Link
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={show(quote.id)}
                                        >
                                            #{quote.number}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {quote.seller_name}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {quote.customer ??
                                            'Consumidor não identificado'}
                                    </td>
                                    <td className="p-3">
                                        {formatDate(quote.issue_date)}
                                    </td>
                                    <td className="p-3">
                                        {formatDate(quote.valid_until)}
                                    </td>
                                    <td className="p-3">
                                        <SaleStatusBadge
                                            status={quote.status}
                                            isOverdue={quote.is_overdue}
                                        />
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(quote.total)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={quotes.links} />
            </div>
        </>
    );
}

SaleQuotes.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Orçamentos', href: index() },
    ],
};
