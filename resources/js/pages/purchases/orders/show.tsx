import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ConfirmButton from '@/components/confirm-button';
import Heading from '@/components/heading';
import ReasonDialog from '@/components/purchases/reason-dialog';
import ReceiveDialog from '@/components/purchases/receive-dialog';
import ReturnDialog from '@/components/purchases/return-dialog';
import StatusBadge from '@/components/purchases/status-badge';
import type { Tab } from '@/components/tabs';
import Tabs from '@/components/tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { dashboard } from '@/routes/purchases';
import {
    cancel,
    closeBalance,
    confirm,
    document,
    edit,
    index,
} from '@/routes/purchases/orders';
import { reverse } from '@/routes/purchases/receipts';
import { show as supplierShow } from '@/routes/purchases/suppliers';
import type {
    PurchaseOrder,
    PurchaseReceipt,
    PurchaseReturn,
    SupplierSummary,
} from '@/types';

function Facts({ items }: { items: [string, ReactNode][] }) {
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

function quantity(value: string, unit: string): string {
    return `${formatDecimal(value)} ${unit}`;
}

export default function OrderShow({
    order,
    supplier,
    receipts,
    returns,
    today,
}: {
    order: PurchaseOrder;
    supplier: SupplierSummary;
    receipts: PurchaseReceipt[];
    returns: PurchaseReturn[];
    today: string;
}) {
    const { can, formatDateTime } = useTenant();
    const canViewCost = can('purchases.view_cost');
    const status = order.status.value;
    const isPending = status === 'confirmed' || status === 'partially_received';
    const hasReceipts = receipts.some((receipt) => !receipt.is_reversed);
    const snapshot = order.supplier_snapshot;

    const tabs: Tab[] = [
        {
            key: 'general',
            label: 'Dados gerais',
            content: (
                <div className="space-y-6">
                    <Facts
                        items={[
                            ['Fornecedor (no pedido)', snapshot.name],
                            ['CPF/CNPJ', snapshot.document],
                            [
                                'Contato',
                                [snapshot.phone, snapshot.email]
                                    .filter(Boolean)
                                    .join(' · ') || null,
                            ],
                            ['Endereço', snapshot.address],
                            ['Data do pedido', formatDate(order.order_date)],
                            [
                                'Previsão de entrega',
                                formatDate(order.expected_date),
                            ],
                            ['Responsável', order.user_name],
                            ['Referência externa', order.external_reference],
                            [
                                'Confirmado em',
                                order.confirmed_at
                                    ? formatDateTime(order.confirmed_at)
                                    : null,
                            ],
                        ]}
                    />
                    {order.notes && (
                        <p className="text-sm whitespace-pre-line">
                            {order.notes}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'items',
            label: `Itens (${order.items.length})`,
            content: (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-2">Item</th>
                                <th className="p-2 text-right">Pedido</th>
                                <th className="p-2 text-right">Recebido</th>
                                <th className="p-2 text-right">Pendente</th>
                                {canViewCost && (
                                    <>
                                        <th className="p-2 text-right">
                                            Custo unit.
                                        </th>
                                        <th className="p-2 text-right">
                                            Desconto
                                        </th>
                                        <th className="p-2 text-right">
                                            Total
                                        </th>
                                        <th className="p-2 text-right">
                                            Custo de aquisição
                                        </th>
                                    </>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {order.items.map((item) => (
                                <tr key={item.id} className="border-t">
                                    <td className="p-2">
                                        {item.description}
                                        <span className="block text-xs text-muted-foreground">
                                            {[
                                                item.sku,
                                                item.supplier_code &&
                                                    `Forn.: ${item.supplier_code}`,
                                                item.is_service && 'Serviço',
                                                item.is_free && 'Bonificado',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </td>
                                    <td className="p-2 text-right whitespace-nowrap">
                                        {quantity(
                                            item.quantity,
                                            item.unit_code,
                                        )}
                                    </td>
                                    <td className="p-2 text-right whitespace-nowrap">
                                        {quantity(
                                            item.received_quantity,
                                            item.unit_code,
                                        )}
                                    </td>
                                    <td className="p-2 text-right whitespace-nowrap">
                                        {quantity(
                                            item.pending_quantity,
                                            item.unit_code,
                                        )}
                                    </td>
                                    {canViewCost && (
                                        <>
                                            <td className="p-2 text-right">
                                                {formatMoney(item.unit_cost)}
                                            </td>
                                            <td className="p-2 text-right">
                                                {formatMoney(item.discount)}
                                            </td>
                                            <td className="p-2 text-right">
                                                {formatMoney(item.total)}
                                            </td>
                                            <td className="p-2 text-right">
                                                {item.acquisition_total
                                                    ? formatMoney(
                                                          item.acquisition_total,
                                                      )
                                                    : 'Na confirmação'}
                                            </td>
                                        </>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ),
        },
        {
            key: 'receipts',
            label: `Recebimentos (${receipts.length})`,
            content:
                receipts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nenhum recebimento registrado.
                    </p>
                ) : (
                    <div className="space-y-4">
                        {receipts.map((receipt) => (
                            <article
                                key={receipt.id}
                                className="space-y-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h4 className="font-medium">
                                            Recebimento #{receipt.number} ·{' '}
                                            {formatDate(receipt.received_at)}{' '}
                                            {receipt.is_reversed && (
                                                <Badge variant="destructive">
                                                    Estornado
                                                </Badge>
                                            )}
                                        </h4>
                                        <p className="text-xs text-muted-foreground">
                                            {[
                                                receipt.user_name,
                                                receipt.document_reference &&
                                                    `Documento ${receipt.document_reference}`,
                                                canViewCost &&
                                                    `Custo ${formatMoney(receipt.total_cost)}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        {receipt.is_reversed && (
                                            <p className="text-xs text-destructive">
                                                Estornado por{' '}
                                                {receipt.reversed_by_name}:{' '}
                                                {receipt.reversal_reason}
                                            </p>
                                        )}
                                    </div>
                                    {!receipt.is_reversed &&
                                        can('purchases.return') && (
                                            <div className="flex gap-2">
                                                <ReturnDialog
                                                    receipt={receipt}
                                                    today={today}
                                                />
                                                {receipt.items.every(
                                                    (item) =>
                                                        Number(
                                                            item.returned_quantity,
                                                        ) === 0,
                                                ) && (
                                                    <ReasonDialog
                                                        size="sm"
                                                        url={reverse.url(
                                                            receipt.id,
                                                        )}
                                                        title={`Estornar recebimento #${receipt.number}`}
                                                        description="Use o estorno para corrigir um lançamento incorreto. As entradas de estoque são revertidas e o pedido volta a ter saldo pendente. Para mercadoria devolvida ao fornecedor, use a devolução."
                                                        confirmLabel="Estornar"
                                                    >
                                                        Estornar
                                                    </ReasonDialog>
                                                )}
                                            </div>
                                        )}
                                </div>
                                <ul className="divide-y text-sm">
                                    {receipt.items.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex flex-wrap justify-between gap-2 py-1"
                                        >
                                            <span>
                                                {item.description}
                                                {item.divergence && (
                                                    <span className="block text-xs text-amber-700 dark:text-amber-400">
                                                        Divergência:{' '}
                                                        {item.divergence}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="text-right whitespace-nowrap">
                                                {quantity(
                                                    item.quantity,
                                                    item.unit_code,
                                                )}
                                                {Number(
                                                    item.returned_quantity,
                                                ) > 0 &&
                                                    ` · devolvido ${formatDecimal(item.returned_quantity)}`}
                                                {canViewCost && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        Custo efetivo unit.{' '}
                                                        {formatMoney(
                                                            item.unit_cost,
                                                        )}
                                                    </span>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                {receipt.notes && (
                                    <p className="text-xs whitespace-pre-line text-muted-foreground">
                                        {receipt.notes}
                                    </p>
                                )}
                            </article>
                        ))}
                    </div>
                ),
        },
        ...(canViewCost
            ? [
                  {
                      key: 'values',
                      label: 'Valores',
                      content: (
                          <dl className="max-w-md space-y-2 text-sm">
                              {(
                                  [
                                      ['Total dos itens', order.items_total],
                                      ['Desconto geral', order.discount],
                                      ['Frete', order.freight],
                                      ['Seguro', order.insurance],
                                      ['Outras despesas', order.other_costs],
                                  ] as const
                              ).map(([label, value]) => (
                                  <div
                                      key={label}
                                      className="flex justify-between gap-4"
                                  >
                                      <dt className="text-muted-foreground">
                                          {label}
                                      </dt>
                                      <dd>{formatMoney(value)}</dd>
                                  </div>
                              ))}
                              <div className="flex justify-between gap-4 border-t pt-2 text-base font-semibold">
                                  <dt>Total do pedido</dt>
                                  <dd>{formatMoney(order.total)}</dd>
                              </div>
                              <p className="pt-2 text-xs text-muted-foreground">
                                  Valores pedidos. Pagamentos efetivos dependem
                                  do módulo Financeiro.
                              </p>
                          </dl>
                      ),
                  },
                  {
                      key: 'payment',
                      label: 'Pagamento',
                      content: (
                          <div className="space-y-3 text-sm">
                              <p>
                                  {order.payment_condition.label}
                                  {order.payment_method &&
                                      ` · ${order.payment_method.label}`}
                              </p>
                              {order.payment_schedules.length > 0 && (
                                  <table className="w-full max-w-lg">
                                      <thead className="text-left text-muted-foreground">
                                          <tr>
                                              <th className="py-1">Parcela</th>
                                              <th className="py-1">
                                                  Vencimento
                                              </th>
                                              <th className="py-1">Forma</th>
                                              <th className="py-1 text-right">
                                                  Valor
                                              </th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          {order.payment_schedules.map(
                                              (schedule) => (
                                                  <tr
                                                      key={schedule.sequence}
                                                      className="border-t"
                                                  >
                                                      <td className="py-1">
                                                          {schedule.is_entry
                                                              ? 'Entrada'
                                                              : `${schedule.sequence}ª`}
                                                      </td>
                                                      <td className="py-1">
                                                          {formatDate(
                                                              schedule.due_date,
                                                          )}
                                                      </td>
                                                      <td className="py-1">
                                                          {schedule.method ??
                                                              '—'}
                                                      </td>
                                                      <td className="py-1 text-right">
                                                          {formatMoney(
                                                              schedule.amount,
                                                          )}
                                                      </td>
                                                  </tr>
                                              ),
                                          )}
                                      </tbody>
                                  </table>
                              )}
                              {order.payment_notes && (
                                  <p className="text-muted-foreground">
                                      {order.payment_notes}
                                  </p>
                              )}
                              <p className="text-xs text-muted-foreground">
                                  Previsão comercial: não representa pagamento
                                  efetuado.
                              </p>
                          </div>
                      ),
                  },
              ]
            : []),
        {
            key: 'returns',
            label: `Devoluções (${returns.length})`,
            content:
                returns.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nenhuma devolução registrada.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {returns.map((purchaseReturn) => (
                            <li
                                key={purchaseReturn.id}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <p className="font-medium">
                                    Devolução #{purchaseReturn.number} ·{' '}
                                    {formatDate(purchaseReturn.returned_at)} ·
                                    recebimento #{purchaseReturn.receipt_number}
                                    {canViewCost &&
                                        ` · ${formatMoney(purchaseReturn.total_value)}`}
                                </p>
                                <p className="text-muted-foreground">
                                    {purchaseReturn.reason} —{' '}
                                    {purchaseReturn.user_name}
                                </p>
                                <ul className="mt-1">
                                    {purchaseReturn.items.map((item) => (
                                        <li key={item.id}>
                                            {item.description}:{' '}
                                            {quantity(
                                                item.quantity,
                                                item.unit_code,
                                            )}
                                            {canViewCost &&
                                                ` · valor histórico ${formatMoney(item.historical_value)}`}
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                ),
        },
        {
            key: 'history',
            label: 'Histórico',
            content: (
                <ol className="space-y-2 text-sm">
                    {order.history.map((change) => (
                        <li key={change.id} className="border-l-2 pl-3">
                            <span className="font-medium">
                                {change.from
                                    ? `${change.from} → ${change.to}`
                                    : change.to}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {formatDateTime(change.occurred_at)} ·{' '}
                                {change.user_name}
                            </span>
                            {change.reason && (
                                <span className="block">{change.reason}</span>
                            )}
                        </li>
                    ))}
                </ol>
            ),
        },
    ];

    return (
        <>
            <Head title={`Pedido de compra #${order.number}`} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={`Pedido de compra #${order.number}`}
                        />
                        <p className="text-sm">
                            <Link
                                className="underline-offset-4 hover:underline"
                                href={supplierShow(supplier.id)}
                            >
                                {supplier.name}
                            </Link>
                        </p>
                        <StatusBadge
                            status={order.status}
                            isLate={order.is_late}
                        />
                        {order.is_quick && (
                            <span className="ml-2 text-xs text-muted-foreground">
                                Compra rápida
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a
                                href={document.url(order.id)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                PDF
                            </a>
                        </Button>
                        {status === 'draft' && can('purchases.update') && (
                            <Button variant="outline" asChild>
                                <Link href={edit(order.id)}>Editar</Link>
                            </Button>
                        )}
                        {(status === 'draft' ||
                            (status === 'confirmed' && !hasReceipts)) &&
                            can('purchases.cancel') && (
                                <ReasonDialog
                                    url={cancel.url(order.id)}
                                    title={`Cancelar pedido #${order.number}`}
                                    description="O pedido cancelado não pode ser reaberto. Nenhuma movimentação de estoque é gerada."
                                    confirmLabel="Cancelar pedido"
                                >
                                    Cancelar pedido
                                </ReasonDialog>
                            )}
                        {status === 'partially_received' &&
                            can('purchases.cancel') && (
                                <ReasonDialog
                                    url={closeBalance.url(order.id)}
                                    title="Encerrar saldo pendente"
                                    description="O pedido passa a recebido com as quantidades já entregues. Custos dos itens não recebidos não entram no estoque."
                                    confirmLabel="Encerrar saldo"
                                >
                                    Encerrar saldo
                                </ReasonDialog>
                            )}
                        {status === 'draft' && can('purchases.confirm') && (
                            <ConfirmButton
                                variant="default"
                                route={{
                                    url: confirm.url(order.id),
                                    method: 'post',
                                }}
                                title={`Confirmar pedido #${order.number}`}
                                description="Os custos de aquisição são fixados e o pedido fica disponível para recebimento. O estoque só muda no recebimento."
                                confirmLabel="Confirmar"
                            >
                                Confirmar pedido
                            </ConfirmButton>
                        )}
                        {isPending && can('purchases.receive') && (
                            <ReceiveDialog order={order} today={today} />
                        )}
                    </div>
                </div>
                <Tabs label="Ficha do pedido" tabs={tabs} />
            </div>
        </>
    );
}

OrderShow.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Pedidos de compra', href: index() },
        { title: 'Ficha do pedido', href: '#' },
    ],
};
