import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { dashboard } from '@/routes/inventory';
import { index as items } from '@/routes/inventory/items';
import { index as movements } from '@/routes/inventory/movements';
import type { StockMovement } from '@/types';

type Indicators = {
    items: number;
    with_balance: number;
    without_balance: number;
    low_stock: number;
    entries: number;
    exits: number;
    no_movement: number;
    value: {
        value: string;
        items_with_cost: number;
        items_without_cost: number;
    } | null;
    by_warehouse: {
        id: number;
        name: string;
        items: number;
        quantity: string;
    }[];
};

function Stat({
    label,
    value,
    href,
    tone,
}: {
    label: string;
    value: string;
    href?: string;
    tone?: 'alert';
}) {
    const content = (
        <>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={`text-2xl font-semibold ${tone === 'alert' ? 'text-destructive' : ''}`}
            >
                {value}
            </dd>
        </>
    );

    return href ? (
        <Link
            href={href}
            className="block rounded-lg border p-4 hover:bg-muted/50"
        >
            {content}
        </Link>
    ) : (
        <div className="rounded-lg border p-4">{content}</div>
    );
}

export default function StockDashboard({
    indicators,
    filters,
    idleDays,
    recent,
    startedAt,
}: {
    indicators: Indicators;
    filters: { from: string; until: string };
    idleDays: number;
    recent: StockMovement[];
    startedAt: string | null;
}) {
    const { formatDateTime } = useTenant();
    const form = useForm({ from: filters.from, until: filters.until });

    return (
        <>
            <Head title="Estoque" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Estoque"
                        description={
                            startedAt
                                ? `Controle de estoque desde ${formatDate(startedAt)}.`
                                : 'Cadastre mercadorias e informe o saldo inicial para começar o controle.'
                        }
                    />
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.get(dashboard.url(), { preserveScroll: true });
                        }}
                    >
                        <Input
                            type="date"
                            aria-label="Período: início"
                            className="w-40"
                            value={form.data.from}
                            onChange={(event) =>
                                form.setData('from', event.target.value)
                            }
                        />
                        <Input
                            type="date"
                            aria-label="Período: fim"
                            className="w-40"
                            value={form.data.until}
                            onChange={(event) =>
                                form.setData('until', event.target.value)
                            }
                        />
                        <Button variant="outline" disabled={form.processing}>
                            Atualizar
                        </Button>
                    </form>
                </div>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label="Mercadorias cadastradas"
                        value={String(indicators.items)}
                        href={items.url()}
                    />
                    <Stat
                        label="Com saldo"
                        value={String(indicators.with_balance)}
                        href={items.url({
                            query: { situation: 'with_balance' },
                        })}
                    />
                    <Stat
                        label="Sem saldo"
                        value={String(indicators.without_balance)}
                        href={items.url({ query: { situation: 'zero' } })}
                    />
                    <Stat
                        label="Abaixo do mínimo"
                        value={String(indicators.low_stock)}
                        href={items.url({ query: { situation: 'low' } })}
                        tone={indicators.low_stock > 0 ? 'alert' : undefined}
                    />
                    <Stat
                        label="Entradas no período"
                        value={String(indicators.entries)}
                        href={movements.url({
                            query: { from: filters.from, until: filters.until },
                        })}
                    />
                    <Stat
                        label="Saídas no período"
                        value={String(indicators.exits)}
                        href={movements.url({
                            query: { from: filters.from, until: filters.until },
                        })}
                    />
                    <Stat
                        label={`Sem movimentação há ${idleDays} dias`}
                        value={String(indicators.no_movement)}
                    />
                    {indicators.value !== null && (
                        <div className="rounded-lg border p-4">
                            <dt className="text-sm text-muted-foreground">
                                Valor estimado (custo médio)
                            </dt>
                            <dd className="text-2xl font-semibold">
                                {formatMoney(indicators.value.value)}
                            </dd>
                            {indicators.value.items_without_cost > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {indicators.value.items_without_cost}{' '}
                                    mercadoria(s) com saldo sem custo conhecido
                                    não entram no valor.
                                </p>
                            )}
                        </div>
                    )}
                </dl>

                <div className="grid gap-6 lg:grid-cols-3">
                    <section
                        aria-label="Distribuição por depósito"
                        className="space-y-3"
                    >
                        <h3 className="font-medium">
                            Distribuição por depósito
                        </h3>
                        <ul className="space-y-2">
                            {indicators.by_warehouse.map((warehouse) => (
                                <li
                                    key={warehouse.id}
                                    className="flex justify-between gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <span>{warehouse.name}</span>
                                    <span className="text-muted-foreground">
                                        {warehouse.items} item(ns) ·{' '}
                                        {formatDecimal(warehouse.quantity)} un.
                                    </span>
                                </li>
                            ))}
                            {indicators.by_warehouse.length === 0 && (
                                <li className="text-sm text-muted-foreground">
                                    Nenhum depósito com saldo.
                                </li>
                            )}
                        </ul>
                    </section>
                    <section
                        aria-label="Últimas movimentações"
                        className="space-y-3 lg:col-span-2"
                    >
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">
                                Últimas movimentações
                            </h3>
                            <Link
                                href={movements()}
                                className="text-sm underline-offset-4 hover:underline"
                            >
                                Ver todas
                            </Link>
                        </div>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Data</th>
                                        <th className="p-3">Mercadoria</th>
                                        <th className="p-3">Tipo</th>
                                        <th className="p-3 text-right">
                                            Quantidade
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="p-6 text-center text-muted-foreground"
                                            >
                                                Nenhuma movimentação.
                                            </td>
                                        </tr>
                                    )}
                                    {recent.map((movement) => (
                                        <tr
                                            key={movement.id}
                                            className="border-t"
                                        >
                                            <td className="p-3 whitespace-nowrap">
                                                {formatDateTime(
                                                    movement.occurred_at,
                                                )}
                                            </td>
                                            <td className="p-3">
                                                {movement.item}
                                                <span className="block text-xs text-muted-foreground">
                                                    {movement.warehouse ?? '—'}
                                                </span>
                                            </td>
                                            <td className="p-3">
                                                {movement.type_label}
                                                <span className="block text-xs text-muted-foreground">
                                                    {movement.origin_label}
                                                </span>
                                            </td>
                                            <td
                                                className={`p-3 text-right whitespace-nowrap ${movement.quantity.startsWith('-') ? 'text-destructive' : ''}`}
                                            >
                                                {formatDecimal(
                                                    movement.quantity,
                                                )}{' '}
                                                {movement.unit}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

StockDashboard.layout = { breadcrumbs: [inventoryBreadcrumb] };
