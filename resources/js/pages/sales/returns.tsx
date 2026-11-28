import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { dashboard } from '@/routes/sales';
import { show } from '@/routes/sales/orders';
import { document, index } from '@/routes/sales/returns';
import type { Paginated, SaleReturn } from '@/types';

export default function SaleReturns({
    returns,
    filters,
}: {
    returns: Paginated<SaleReturn>;
    filters: Partial<Record<'from' | 'until', string | null>>;
}) {
    const form = useForm({
        from: filters.from ?? '',
        until: filters.until ?? '',
    });

    return (
        <>
            <Head title="Devoluções de venda" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Devoluções de venda"
                    description="Valores históricos devolvidos. O acerto financeiro com o cliente é feito no Financeiro."
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        type="date"
                        aria-label="A partir de"
                        className="w-44"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Até"
                        className="w-44"
                        value={form.data.until}
                        onChange={(event) =>
                            form.setData('until', event.target.value)
                        }
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Devolução</th>
                                <th className="p-3">Venda</th>
                                <th className="p-3">Data</th>
                                <th className="p-3">Itens</th>
                                <th className="p-3">Motivo</th>
                                <th className="p-3 text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            {returns.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={6}
                                    >
                                        Nenhuma devolução encontrada.
                                    </td>
                                </tr>
                            )}
                            {returns.data.map((saleReturn) => (
                                <tr
                                    key={saleReturn.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <a
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={document.url(saleReturn.id)}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            #{saleReturn.number}
                                        </a>
                                        <span className="block text-xs text-muted-foreground">
                                            {saleReturn.restock
                                                ? 'Com retorno ao estoque'
                                                : 'Sem retorno ao estoque'}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {saleReturn.order && (
                                            <Link
                                                className="underline-offset-4 hover:underline"
                                                href={show(saleReturn.order.id)}
                                            >
                                                #{saleReturn.order.number}
                                            </Link>
                                        )}
                                        <span className="block text-xs text-muted-foreground">
                                            {saleReturn.order?.customer ??
                                                'Consumidor não identificado'}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {formatDate(saleReturn.returned_at)}
                                    </td>
                                    <td className="p-3">
                                        {saleReturn.items.map((item, index) => (
                                            <span key={index} className="block">
                                                {item.description}:{' '}
                                                {formatDecimal(item.quantity)}{' '}
                                                {item.unit_code}
                                            </span>
                                        ))}
                                    </td>
                                    <td className="p-3">{saleReturn.reason}</td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(saleReturn.total_value)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={returns.links} />
            </div>
        </>
    );
}

SaleReturns.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Devoluções', href: index() },
    ],
};
