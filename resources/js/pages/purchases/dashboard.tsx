import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney, formatPercentage } from '@/lib/decimal';
import { dashboard } from '@/routes/purchases';
import { index as orders } from '@/routes/purchases/orders';

type Indicators = {
    period: { from: string; until: string };
    orders_count: number;
    pending_count: number;
    late_count: number;
    draft_count: number;
    ordered_value?: string;
    confirmed_open_value?: string;
    received_value?: string;
    returned_value?: string;
    top_suppliers: {
        supplier_profile_id: number;
        name: string;
        orders: number;
        value?: string;
    }[];
    top_products: {
        product_variant_id: number;
        description: string;
        unit_code: string;
        quantity: string;
        value?: string;
    }[];
    monthly?: { month: string; ordered: string; received: string }[];
    cost_changes?: {
        product_variant_id: number;
        description: string;
        unit_code: string;
        previous: string;
        current: string;
        variation: string | null;
    }[];
};

const MONTHS = [
    'jan',
    'fev',
    'mar',
    'abr',
    'mai',
    'jun',
    'jul',
    'ago',
    'set',
    'out',
    'nov',
    'dez',
];

function Card({
    label,
    value,
    href,
}: {
    label: string;
    value: string | number;
    href?: string;
}) {
    const body = (
        <>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-xl font-semibold">{value}</dd>
        </>
    );

    return href ? (
        <Link
            href={href}
            className="block rounded-lg border p-3 hover:bg-muted/50"
        >
            {body}
        </Link>
    ) : (
        <div className="rounded-lg border p-3">{body}</div>
    );
}

export default function PurchasesDashboard({
    indicators,
}: {
    indicators: Indicators;
}) {
    const { can } = useTenant();
    const form = useForm({
        from: indicators.period.from,
        until: indicators.period.until,
    });
    const monthly = indicators.monthly ?? [];
    const peak = monthly.reduce(
        (max, month) =>
            Math.max(max, Number(month.ordered), Number(month.received)),
        0,
    );

    return (
        <>
            <Head title="Compras" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Visão geral de compras"
                        description={`Período de ${formatDate(indicators.period.from)} a ${formatDate(indicators.period.until)}.`}
                    />
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.get(dashboard.url(), { preserveState: true });
                        }}
                    >
                        <Input
                            type="date"
                            aria-label="De"
                            value={form.data.from}
                            onChange={(event) =>
                                form.setData('from', event.target.value)
                            }
                        />
                        <Input
                            type="date"
                            aria-label="Até"
                            value={form.data.until}
                            onChange={(event) =>
                                form.setData('until', event.target.value)
                            }
                        />
                        <Button variant="outline" disabled={form.processing}>
                            Aplicar
                        </Button>
                    </form>
                </div>
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <Card
                        label="Compras no período"
                        value={indicators.orders_count}
                    />
                    {indicators.ordered_value !== undefined && (
                        <Card
                            label="Valor pedido no período"
                            value={formatMoney(indicators.ordered_value)}
                        />
                    )}
                    {indicators.received_value !== undefined && (
                        <Card
                            label="Mercadorias recebidas no período"
                            value={formatMoney(indicators.received_value)}
                        />
                    )}
                    {indicators.returned_value !== undefined && (
                        <Card
                            label="Devolvido no período"
                            value={formatMoney(indicators.returned_value)}
                        />
                    )}
                    {indicators.confirmed_open_value !== undefined && (
                        <Card
                            label="Pedidos confirmados em aberto"
                            value={formatMoney(indicators.confirmed_open_value)}
                        />
                    )}
                    <Card
                        label="Aguardando recebimento"
                        value={indicators.pending_count}
                        href={orders.url({ query: { situation: 'pending' } })}
                    />
                    <Card
                        label="Atrasados"
                        value={indicators.late_count}
                        href={orders.url({ query: { situation: 'late' } })}
                    />
                    <Card
                        label="Rascunhos"
                        value={indicators.draft_count}
                        href={orders.url({ query: { status: 'draft' } })}
                    />
                </dl>
                <p className="text-xs text-muted-foreground">
                    Valores pedidos e recebidos são operacionais. Valores
                    efetivamente pagos serão exibidos quando o módulo Financeiro
                    estiver disponível.
                </p>
                <div className="grid gap-6 lg:grid-cols-2">
                    <section
                        className="space-y-2 rounded-lg border p-4"
                        aria-labelledby="suppliers-heading"
                    >
                        <h3 id="suppliers-heading" className="font-medium">
                            Principais fornecedores
                        </h3>
                        {indicators.top_suppliers.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Sem compras no período.
                            </p>
                        )}
                        <ol className="space-y-1 text-sm">
                            {indicators.top_suppliers.map((supplier) => (
                                <li
                                    key={supplier.supplier_profile_id}
                                    className="flex justify-between gap-2"
                                >
                                    <span>{supplier.name}</span>
                                    <span className="text-muted-foreground">
                                        {supplier.orders} pedido(s)
                                        {supplier.value !== undefined &&
                                            ` · ${formatMoney(supplier.value)}`}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                    <section
                        className="space-y-2 rounded-lg border p-4"
                        aria-labelledby="products-heading"
                    >
                        <h3 id="products-heading" className="font-medium">
                            Produtos mais adquiridos (recebidos)
                        </h3>
                        {indicators.top_products.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Sem recebimentos no período.
                            </p>
                        )}
                        <ol className="space-y-1 text-sm">
                            {indicators.top_products.map((product) => (
                                <li
                                    key={product.product_variant_id}
                                    className="flex justify-between gap-2"
                                >
                                    <span>{product.description}</span>
                                    <span className="text-muted-foreground">
                                        {formatDecimal(product.quantity)}{' '}
                                        {product.unit_code}
                                        {product.value !== undefined &&
                                            ` · ${formatMoney(product.value)}`}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>
                {monthly.length > 0 && (
                    <section
                        className="space-y-3 rounded-lg border p-4"
                        aria-labelledby="monthly-heading"
                    >
                        <h3 id="monthly-heading" className="font-medium">
                            Evolução mensal (12 meses)
                        </h3>
                        <div
                            className="flex h-40 items-end gap-1 overflow-x-auto"
                            role="img"
                            aria-label="Valores pedidos e recebidos por mês"
                        >
                            {monthly.map((month) => (
                                <div
                                    key={month.month}
                                    className="flex min-w-8 flex-1 flex-col items-center gap-1"
                                >
                                    <div className="flex h-32 w-full items-end justify-center gap-0.5">
                                        <div
                                            className="w-1/2 rounded-t bg-primary"
                                            style={{
                                                height: `${peak === 0 ? 0 : (Number(month.ordered) / peak) * 100}%`,
                                            }}
                                            title={`Pedido: ${formatMoney(month.ordered)}`}
                                        />
                                        <div
                                            className="w-1/2 rounded-t bg-muted-foreground/40"
                                            style={{
                                                height: `${peak === 0 ? 0 : (Number(month.received) / peak) * 100}%`,
                                            }}
                                            title={`Recebido: ${formatMoney(month.received)}`}
                                        />
                                    </div>
                                    <span className="text-[10px] text-muted-foreground">
                                        {
                                            MONTHS[
                                                Number(month.month.slice(5)) - 1
                                            ]
                                        }
                                    </span>
                                </div>
                            ))}
                        </div>
                        <p className="flex gap-4 text-xs text-muted-foreground">
                            <span>
                                <span className="mr-1 inline-block size-2 rounded-sm bg-primary" />
                                Pedido
                            </span>
                            <span>
                                <span className="mr-1 inline-block size-2 rounded-sm bg-muted-foreground/40" />
                                Recebido
                            </span>
                        </p>
                        <table className="sr-only">
                            <caption>Valores mensais</caption>
                            <tbody>
                                {monthly.map((month) => (
                                    <tr key={month.month}>
                                        <th>{month.month}</th>
                                        <td>{formatMoney(month.ordered)}</td>
                                        <td>{formatMoney(month.received)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}
                {indicators.cost_changes !== undefined && (
                    <section
                        className="space-y-2 rounded-lg border p-4"
                        aria-labelledby="costs-heading"
                    >
                        <h3 id="costs-heading" className="font-medium">
                            Variação dos custos de aquisição
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Custo efetivo unitário (com rateio) do último
                            recebimento do período comparado ao recebimento
                            anterior.
                        </p>
                        {indicators.cost_changes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Sem itens com recebimentos anteriores para
                                comparar.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-left text-muted-foreground">
                                        <tr>
                                            <th className="py-1">Item</th>
                                            <th className="py-1 text-right">
                                                Anterior
                                            </th>
                                            <th className="py-1 text-right">
                                                Atual
                                            </th>
                                            <th className="py-1 text-right">
                                                Variação
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {indicators.cost_changes.map(
                                            (change) => (
                                                <tr
                                                    key={
                                                        change.product_variant_id
                                                    }
                                                    className="border-t"
                                                >
                                                    <td className="py-1">
                                                        {change.description}
                                                    </td>
                                                    <td className="py-1 text-right">
                                                        {formatMoney(
                                                            change.previous,
                                                        )}
                                                    </td>
                                                    <td className="py-1 text-right">
                                                        {formatMoney(
                                                            change.current,
                                                        )}
                                                    </td>
                                                    <td className="py-1 text-right">
                                                        {formatPercentage(
                                                            change.variation,
                                                        )}
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}
                {!can('purchases.view_cost') && (
                    <p className="text-sm text-muted-foreground">
                        Valores de compra são exibidos apenas a quem pode
                        consultar custos.
                    </p>
                )}
            </div>
        </>
    );
}

PurchasesDashboard.layout = {
    breadcrumbs: [{ title: 'Compras', href: dashboard() }],
};
