import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import SaleStatusBadge from '@/components/sales/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { dashboard } from '@/routes/sales';
import { document, index } from '@/routes/sales/fulfillments';
import { show } from '@/routes/sales/orders';
import type { Paginated, SaleFulfillment } from '@/types';

export default function SaleFulfillments({
    fulfillments,
    filters,
}: {
    fulfillments: Paginated<SaleFulfillment>;
    filters: Partial<
        Record<'from' | 'until' | 'status' | 'search', string | null>
    >;
}) {
    const form = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
    });
    const { data, setData } = form;

    return (
        <>
            <Head title="Atendimentos" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Atendimentos"
                    description="Retiradas, entregas e serviços registrados."
                />
                <form
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        type="search"
                        aria-label="Número da venda"
                        placeholder="Número da venda"
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
                        <option value="confirmed">Registrados</option>
                        <option value="reversed">Estornados</option>
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label="A partir de"
                        value={data.from}
                        onChange={(event) =>
                            setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Até"
                        value={data.until}
                        onChange={(event) =>
                            setData('until', event.target.value)
                        }
                    />
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
                                <th className="p-3">Atendimento</th>
                                <th className="p-3">Venda</th>
                                <th className="p-3">Data</th>
                                <th className="p-3">Itens</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            {fulfillments.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={6}
                                    >
                                        Nenhum atendimento encontrado.
                                    </td>
                                </tr>
                            )}
                            {fulfillments.data.map((fulfillment) => (
                                <tr
                                    key={fulfillment.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <a
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={document.url(fulfillment.id)}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            #{fulfillment.number}
                                        </a>
                                        <span className="block text-xs text-muted-foreground">
                                            {fulfillment.method.label}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {fulfillment.order && (
                                            <Link
                                                className="underline-offset-4 hover:underline"
                                                href={show(
                                                    fulfillment.order.id,
                                                )}
                                            >
                                                #{fulfillment.order.number}
                                            </Link>
                                        )}
                                        <span className="block text-xs text-muted-foreground">
                                            {fulfillment.order?.customer ??
                                                'Consumidor não identificado'}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {formatDate(fulfillment.fulfilled_at)}
                                    </td>
                                    <td className="p-3">
                                        {fulfillment.items.map((item) => (
                                            <span
                                                key={item.id}
                                                className="block"
                                            >
                                                {item.description}:{' '}
                                                {formatDecimal(item.quantity)}{' '}
                                                {item.unit_code}
                                            </span>
                                        ))}
                                    </td>
                                    <td className="p-3">
                                        <SaleStatusBadge
                                            status={fulfillment.status}
                                        />
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(fulfillment.revenue)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={fulfillments.links} />
            </div>
        </>
    );
}

SaleFulfillments.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Atendimentos', href: index() },
    ],
};
