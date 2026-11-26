import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import StatusBadge from '@/components/purchases/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { dashboard } from '@/routes/purchases';
import { show } from '@/routes/purchases/orders';
import { index } from '@/routes/purchases/receipts';
import type {
    Option,
    Paginated,
    PurchaseOrderSummary,
    PurchaseReceipt,
} from '@/types';

export default function Receipts({
    receipts,
    pendingOrders,
    filters,
    statuses,
}: {
    receipts: Paginated<PurchaseReceipt>;
    pendingOrders: PurchaseOrderSummary[];
    filters: Partial<
        Record<'search' | 'status' | 'from' | 'until', string | null>
    >;
    statuses: Option[];
}) {
    const { can } = useTenant();
    const canViewCost = can('purchases.view_cost');
    const form = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
    });
    const { data, setData } = form;

    return (
        <>
            <Head title="Recebimentos" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Recebimentos"
                    description="Entradas de mercadorias e pedidos aguardando entrega."
                />
                <section
                    aria-labelledby="pending-heading"
                    className="space-y-2"
                >
                    <h3 id="pending-heading" className="text-sm font-medium">
                        Recebimentos pendentes ({pendingOrders.length})
                    </h3>
                    {pendingOrders.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nenhum pedido aguardando recebimento.
                        </p>
                    ) : (
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {pendingOrders.map((order) => (
                                <li key={order.id}>
                                    <Link
                                        href={show(order.id)}
                                        className="block rounded-lg border p-3 text-sm hover:bg-muted/50"
                                    >
                                        <span className="font-medium">
                                            #{order.number}
                                        </span>{' '}
                                        · {order.supplier}
                                        <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <StatusBadge
                                                status={order.status}
                                                isLate={order.is_late}
                                            />
                                            Previsão{' '}
                                            {formatDate(order.expected_date)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
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
                        aria-label="Pesquisar"
                        placeholder="Nº, pedido, fornecedor ou documento"
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
                        <option value="">Todas</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </NativeSelect>
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
                                <th className="p-3">Recebimento</th>
                                <th className="p-3">Pedido</th>
                                <th className="p-3">Fornecedor</th>
                                <th className="p-3">Data</th>
                                <th className="p-3">Documento</th>
                                <th className="p-3">Situação</th>
                                {canViewCost && (
                                    <th className="p-3 text-right">Custo</th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {receipts.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={7}
                                    >
                                        Nenhum recebimento encontrado.
                                    </td>
                                </tr>
                            )}
                            {receipts.data.map((receipt) => (
                                <tr key={receipt.id} className="border-t">
                                    <td className="p-3">#{receipt.number}</td>
                                    <td className="p-3">
                                        <Link
                                            className="underline-offset-4 hover:underline"
                                            href={show(
                                                receipt.purchase_order_id,
                                            )}
                                        >
                                            #{receipt.order_number}
                                        </Link>
                                    </td>
                                    <td className="p-3">{receipt.supplier}</td>
                                    <td className="p-3">
                                        {formatDate(receipt.received_at)}
                                    </td>
                                    <td className="p-3">
                                        {receipt.document_reference ?? '—'}
                                    </td>
                                    <td className="p-3">
                                        <StatusBadge status={receipt.status} />
                                    </td>
                                    {canViewCost && (
                                        <td className="p-3 text-right">
                                            {formatMoney(receipt.total_cost)}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={receipts.links} />
            </div>
        </>
    );
}

Receipts.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Recebimentos', href: index() },
    ],
};
