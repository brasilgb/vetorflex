import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { dashboard } from '@/routes/purchases';
import { show } from '@/routes/purchases/orders';
import { index } from '@/routes/purchases/returns';
import type { Paginated, PurchaseReturn } from '@/types';

export default function Returns({
    returns,
    filters,
}: {
    returns: Paginated<PurchaseReturn>;
    filters: Partial<Record<'search' | 'from' | 'until', string | null>>;
}) {
    const { can } = useTenant();
    const canViewCost = can('purchases.view_cost');
    const form = useForm({
        search: filters.search ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
    });
    const { data, setData } = form;

    return (
        <>
            <Head title="Devoluções" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Devoluções"
                    description="Mercadorias devolvidas aos fornecedores. Registre novas devoluções na ficha do pedido."
                />
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
                        type="search"
                        aria-label="Pesquisar"
                        placeholder="Nº, pedido, fornecedor ou motivo"
                        value={data.search}
                        onChange={(event) =>
                            setData('search', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="De"
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
                                <th className="p-3">Devolução</th>
                                <th className="p-3">Pedido</th>
                                <th className="p-3">Fornecedor</th>
                                <th className="p-3">Data</th>
                                <th className="p-3">Itens</th>
                                <th className="p-3">Motivo</th>
                                {canViewCost && (
                                    <th className="p-3 text-right">
                                        Valor histórico
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {returns.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={7}
                                    >
                                        Nenhuma devolução encontrada.
                                    </td>
                                </tr>
                            )}
                            {returns.data.map((purchaseReturn) => (
                                <tr
                                    key={purchaseReturn.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        #{purchaseReturn.number}
                                    </td>
                                    <td className="p-3">
                                        <Link
                                            className="underline-offset-4 hover:underline"
                                            href={show(
                                                purchaseReturn.purchase_order_id,
                                            )}
                                        >
                                            #{purchaseReturn.order_number}
                                        </Link>
                                    </td>
                                    <td className="p-3">
                                        {purchaseReturn.supplier}
                                    </td>
                                    <td className="p-3">
                                        {formatDate(purchaseReturn.returned_at)}
                                    </td>
                                    <td className="p-3">
                                        {purchaseReturn.items.map((item) => (
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
                                        {purchaseReturn.reason}
                                    </td>
                                    {canViewCost && (
                                        <td className="p-3 text-right">
                                            {formatMoney(
                                                purchaseReturn.total_value,
                                            )}
                                        </td>
                                    )}
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

Returns.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Devoluções', href: index() },
    ],
};
