import { Head, Link, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney, formatPercentage } from '@/lib/decimal';
import { dashboard } from '@/routes/sales';
import { create as createOrder, index as orders } from '@/routes/sales/orders';
import { create as createQuote, index as quotes } from '@/routes/sales/quotes';
import type { SaleIndicators } from '@/types';

function Tile({
    label,
    value,
    hint,
}: {
    label: string;
    value: ReactNode;
    hint?: string;
}) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold">{value}</p>
            {hint && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

function Ranking({
    title,
    rows,
    empty,
}: {
    title: string;
    rows: { key: string; label: string; detail: string; value: string }[];
    empty: string;
}) {
    return (
        <section className="space-y-2 rounded-lg border p-4" aria-label={title}>
            <h3 className="font-medium">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ol className="space-y-1 text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.key}
                            className="flex justify-between gap-3"
                        >
                            <span>
                                {row.label}
                                <span className="block text-xs text-muted-foreground">
                                    {row.detail}
                                </span>
                            </span>
                            <span className="whitespace-nowrap">
                                {formatMoney(row.value)}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}

export default function SalesDashboard({
    indicators,
    period,
    stockEnabled,
}: {
    indicators: SaleIndicators | null;
    period: { from: string; until: string };
    stockEnabled: boolean;
}) {
    const { can } = useTenant();
    const form = useForm({ from: period.from, until: period.until });
    const highest = Math.max(
        1,
        ...(indicators?.monthly ?? []).map((month) => Number(month.sold)),
    );

    return (
        <>
            <Head title="Vendas" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Vendas"
                        description={`Valores vendidos, não recebidos. ${stockEnabled ? 'Estoque integrado.' : 'Sem integração com estoque.'}`}
                    />
                    <div className="flex flex-wrap gap-2">
                        {can('sales.manage_quotes') && (
                            <Button variant="outline" asChild>
                                <Link href={createQuote()}>Novo orçamento</Link>
                            </Button>
                        )}
                        {can('sales.create') && (
                            <Button asChild>
                                <Link
                                    href={createOrder({ query: { quick: 1 } })}
                                >
                                    Nova venda
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
                {indicators === null ? (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                        Você não tem permissão para consultar os indicadores.
                        Use os menus de{' '}
                        <Link className="underline" href={orders()}>
                            vendas
                        </Link>{' '}
                        e{' '}
                        <Link className="underline" href={quotes()}>
                            orçamentos
                        </Link>
                        .
                    </p>
                ) : (
                    <>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.get(dashboard.url(), {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <Input
                                type="date"
                                aria-label="Período: início"
                                className="w-44"
                                value={form.data.from}
                                onChange={(event) =>
                                    form.setData('from', event.target.value)
                                }
                            />
                            <Input
                                type="date"
                                aria-label="Período: fim"
                                className="w-44"
                                value={form.data.until}
                                onChange={(event) =>
                                    form.setData('until', event.target.value)
                                }
                            />
                            <Button
                                variant="outline"
                                disabled={form.processing}
                            >
                                Aplicar período
                            </Button>
                            <p className="text-sm text-muted-foreground">
                                {formatDate(indicators.period.from)} a{' '}
                                {formatDate(indicators.period.until)}
                            </p>
                        </form>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Tile
                                label="Total vendido"
                                value={formatMoney(indicators.sold_value)}
                                hint={`Devolvido no período: ${formatMoney(indicators.returned_value)}`}
                            />
                            <Tile
                                label="Vendas"
                                value={indicators.orders_count}
                                hint={`${indicators.completed_count} concluídas · ${indicators.cancelled_count} canceladas`}
                            />
                            <Tile
                                label="Ticket médio"
                                value={formatMoney(indicators.average_ticket)}
                            />
                            <Tile
                                label="Pedidos pendentes"
                                value={indicators.pending_count}
                                hint="Confirmados ou parcialmente atendidos"
                            />
                            <Tile
                                label="Orçamentos emitidos"
                                value={indicators.quotes_issued}
                            />
                            <Tile
                                label="Orçamentos aprovados"
                                value={indicators.quotes_approved}
                            />
                            <Tile
                                label="Taxa de conversão"
                                value={formatPercentage(
                                    indicators.conversion_rate,
                                )}
                                hint="Convertidos em venda / emitidos"
                            />
                            {indicators.margin && (
                                <Tile
                                    label="Margem bruta comercial"
                                    value={formatMoney(
                                        indicators.margin.margin,
                                    )}
                                    hint={`${formatPercentage(indicators.margin.percent)} sobre ${formatMoney(indicators.margin.revenue)}${indicators.margin.items_without_cost > 0 ? ` · ${indicators.margin.items_without_cost} item(ns) sem custo fora do cálculo` : ''}`}
                                />
                            )}
                        </div>
                        <div className="grid gap-4 lg:grid-cols-3">
                            <Ranking
                                title="Produtos mais vendidos"
                                empty="Nenhuma venda no período."
                                rows={indicators.top_products.map(
                                    (row, index) => ({
                                        key: `${row.product_variant_id ?? 'avulso'}-${index}`,
                                        label: row.description,
                                        detail: `${formatDecimal(row.quantity)} ${row.unit_code}`,
                                        value: row.value,
                                    }),
                                )}
                            />
                            <Ranking
                                title="Principais clientes"
                                empty="Nenhuma venda identificada no período."
                                rows={indicators.top_customers.map((row) => ({
                                    key: String(row.customer_party_id),
                                    label: row.name,
                                    detail: `${row.orders} venda(s)`,
                                    value: row.value,
                                }))}
                            />
                            <Ranking
                                title="Vendas por responsável"
                                empty="Nenhuma venda no período."
                                rows={indicators.by_seller.map((row) => ({
                                    key: String(row.seller_id ?? 'none'),
                                    label: row.name,
                                    detail: `${row.orders} venda(s)`,
                                    value: row.value,
                                }))}
                            />
                        </div>
                        <section
                            className="space-y-3 rounded-lg border p-4"
                            aria-label="Evolução mensal"
                        >
                            <h3 className="font-medium">
                                Evolução mensal (12 meses)
                            </h3>
                            <ul className="space-y-1 text-sm">
                                {indicators.monthly.map((month) => (
                                    <li
                                        key={month.month}
                                        className="grid grid-cols-[4.5rem_1fr_8rem] items-center gap-2"
                                    >
                                        <span className="text-muted-foreground">
                                            {month.month
                                                .split('-')
                                                .reverse()
                                                .join('/')}
                                        </span>
                                        <span className="h-3 rounded bg-muted">
                                            <span
                                                className="block h-3 rounded bg-primary"
                                                style={{
                                                    width: `${(Number(month.sold) / highest) * 100}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="text-right whitespace-nowrap">
                                            {formatMoney(month.sold)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

SalesDashboard.layout = {
    breadcrumbs: [{ title: 'Vendas', href: dashboard() }],
};
