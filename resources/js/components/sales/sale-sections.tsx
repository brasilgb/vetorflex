import type { ReactNode } from 'react';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney, formatPercentage } from '@/lib/decimal';
import type { SaleItem, SaleOrder, SaleQuote, StatusChange } from '@/types';

export function Facts({ items }: { items: [string, ReactNode][] }) {
    return (
        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {items.map(([label, value]) => (
                <div key={label}>
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd className="text-sm">{value ?? '—'}</dd>
                </div>
            ))}
        </dl>
    );
}

export function quantity(value: string, unit: string): string {
    return `${formatDecimal(value)} ${unit}`;
}

/** Itens com preço, desconto e total históricos. */
export function ItemsTable({
    items,
    extra,
}: {
    items: SaleItem[];
    extra?: (item: SaleItem) => ReactNode;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem] text-sm">
                <thead className="text-left text-muted-foreground">
                    <tr>
                        <th className="p-2">Item</th>
                        <th className="p-2 text-right">Quantidade</th>
                        <th className="p-2 text-right">Preço unit.</th>
                        <th className="p-2 text-right">Desconto</th>
                        <th className="p-2 text-right">Total</th>
                        {extra && (
                            <th className="p-2 text-right">Atendimento</th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {items.map((item) => (
                        <tr key={item.id} className="border-t align-top">
                            <td className="p-2">
                                {item.description}
                                <span className="block text-xs text-muted-foreground">
                                    {[
                                        item.sku,
                                        item.is_service ? 'Serviço' : null,
                                        item.is_custom ? 'Item avulso' : null,
                                        item.notes,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </td>
                            <td className="p-2 text-right whitespace-nowrap">
                                {quantity(item.quantity, item.unit_code)}
                            </td>
                            <td className="p-2 text-right whitespace-nowrap">
                                {formatMoney(item.unit_price)}
                                {item.catalog_price !== null &&
                                    item.catalog_price !== item.unit_price && (
                                        <span className="block text-xs text-muted-foreground">
                                            Catálogo:{' '}
                                            {formatMoney(item.catalog_price)}
                                        </span>
                                    )}
                            </td>
                            <td className="p-2 text-right whitespace-nowrap">
                                {formatMoney(item.discount)}
                                {item.discount_percent && (
                                    <span className="block text-xs text-muted-foreground">
                                        {formatPercentage(
                                            item.discount_percent,
                                        )}
                                    </span>
                                )}
                            </td>
                            <td className="p-2 text-right whitespace-nowrap">
                                {formatMoney(item.total)}
                            </td>
                            {extra && (
                                <td className="p-2 text-right whitespace-nowrap">
                                    {extra(item)}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function ValuesSection({ sale }: { sale: SaleQuote | SaleOrder }) {
    return (
        <dl className="ml-auto grid max-w-sm gap-2 text-sm">
            {(
                [
                    ['Total dos itens', sale.items_total],
                    ['Desconto geral', sale.discount],
                    ['Frete', sale.freight],
                    ['Outras despesas', sale.other_costs],
                ] as const
            ).map(([label, value]) => (
                <div key={label} className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd>
                        {label === 'Desconto geral' && value !== '0.00'
                            ? '- '
                            : ''}
                        {formatMoney(value)}
                    </dd>
                </div>
            ))}
            <div className="flex justify-between gap-4 border-t pt-2 text-base font-semibold">
                <dt>Total</dt>
                <dd>{formatMoney(sale.total)}</dd>
            </div>
        </dl>
    );
}

export function PaymentSection({ sale }: { sale: SaleQuote | SaleOrder }) {
    return (
        <div className="space-y-4">
            <Facts
                items={[
                    ['Condição', sale.payment_condition.label],
                    ['Forma prevista', sale.payment_method?.label],
                    ['Observações', sale.payment_notes],
                ]}
            />
            {sale.payment_schedules.length > 0 && (
                <table className="w-full max-w-xl text-sm">
                    <thead className="text-left text-muted-foreground">
                        <tr>
                            <th className="p-2">Parcela</th>
                            <th className="p-2">Vencimento</th>
                            <th className="p-2">Forma</th>
                            <th className="p-2 text-right">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sale.payment_schedules.map((schedule) => (
                            <tr key={schedule.sequence} className="border-t">
                                <td className="p-2">
                                    {schedule.is_entry
                                        ? 'Entrada'
                                        : `${schedule.sequence}ª`}
                                </td>
                                <td className="p-2">
                                    {formatDate(schedule.due_date)}
                                </td>
                                <td className="p-2">
                                    {schedule.method ?? '—'}
                                </td>
                                <td className="p-2 text-right">
                                    {formatMoney(schedule.amount)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            <p className="text-xs text-muted-foreground">
                Previsão comercial. Recebimentos são controlados pelo
                Financeiro; a venda não é considerada paga.
            </p>
        </div>
    );
}

export function HistorySection({ history }: { history: StatusChange[] }) {
    const { formatDateTime } = useTenant();

    return (
        <ol className="space-y-3 text-sm">
            {history.map((change) => (
                <li key={change.id} className="border-l-2 pl-3">
                    <p className="font-medium">
                        {change.from
                            ? `${change.from} → ${change.to}`
                            : change.to}
                    </p>
                    <p className="text-muted-foreground">
                        {formatDateTime(change.occurred_at)} ·{' '}
                        {change.user_name ?? '—'}
                    </p>
                    {change.reason && <p>{change.reason}</p>}
                </li>
            ))}
        </ol>
    );
}
