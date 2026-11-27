import { Head, Link } from '@inertiajs/react';
import ConfirmButton from '@/components/confirm-button';
import OriginFiscalDocumentsSection, {
    type OriginFiscalDocuments,
} from '@/components/fiscal/origin-fiscal-documents';
import Heading from '@/components/heading';
import OriginChargesSection, {
    type OriginCharges,
} from '@/components/payments/origin-charges';
import ReasonDialog from '@/components/reason-dialog';
import FulfillDialog from '@/components/sales/fulfill-dialog';
import SaleReturnDialog from '@/components/sales/return-dialog';
import {
    Facts,
    HistorySection,
    ItemsTable,
    PaymentSection,
    quantity,
    ValuesSection,
} from '@/components/sales/sale-sections';
import SaleStatusBadge from '@/components/sales/status-badge';
import type { Tab } from '@/components/tabs';
import Tabs from '@/components/tabs';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney, formatPercentage } from '@/lib/decimal';
import { dashboard } from '@/routes/sales';
import {
    document as fulfillmentDocument,
    reverse,
} from '@/routes/sales/fulfillments';
import {
    cancel,
    closeBalance,
    complete,
    confirm,
    document,
    edit,
    index,
} from '@/routes/sales/orders';
import { show as quoteShow } from '@/routes/sales/quotes';
import { document as returnDocument } from '@/routes/sales/returns';
import type {
    SaleFulfillment,
    SaleOrder,
    SaleOrderItem,
    SaleReturn,
} from '@/types';

const DELIVERY_METHODS = [
    { value: 'pickup', label: 'Retirada no estabelecimento' },
    { value: 'delivery', label: 'Entrega ao cliente' },
    { value: 'service', label: 'Atendimento de serviço' },
];

export default function SaleOrderShow({
    order,
    fulfillments,
    returns,
    stockEnabled,
    paymentCharges = null,
    fiscalDocuments = null,
    today,
}: {
    order: SaleOrder;
    fulfillments: SaleFulfillment[];
    returns: SaleReturn[];
    stockEnabled: boolean;
    paymentCharges?: OriginCharges;
    fiscalDocuments?: OriginFiscalDocuments;
    today: string;
}) {
    const { can } = useTenant();
    const status = order.status.value;
    const isPending =
        status === 'confirmed' || status === 'partially_fulfilled';
    const snapshot = order.customer_snapshot;
    const canViewCost = can('sales.view_cost');
    const acceptsReturns = [
        'partially_fulfilled',
        'fulfilled',
        'completed',
    ].includes(status);

    const tabs: Tab[] = [
        {
            key: 'general',
            label: 'Dados gerais',
            content: (
                <Facts
                    items={[
                        [
                            'Cliente',
                            snapshot?.name ?? 'Consumidor não identificado',
                        ],
                        ['CPF/CNPJ', snapshot?.document],
                        ['Contato', snapshot?.contact ?? snapshot?.phone],
                        ['Responsável', order.seller_name],
                        ['Registrado por', order.user_name],
                        ['Data', formatDate(order.order_date)],
                        ['Previsão', formatDate(order.expected_date)],
                        [
                            'Origem',
                            order.origin.value === 'pos'
                                ? [order.origin.label, order.origin_reference]
                                      .filter(Boolean)
                                      .join(' · ')
                                : order.is_quick
                                  ? 'Venda rápida'
                                  : order.origin.label,
                        ],
                        [
                            'Orçamento',
                            order.sale_quote ? (
                                <Link
                                    className="underline"
                                    href={quoteShow(order.sale_quote.id)}
                                >
                                    #{order.sale_quote.number}
                                </Link>
                            ) : null,
                        ],
                        ['Referência externa', order.external_reference],
                        ['Atendimento', order.delivery_method.label],
                        ['Endereço de entrega', order.delivery_address?.text],
                        ['Condições comerciais', order.commercial_terms],
                        ['Observações ao cliente', order.notes],
                        ['Observações internas', order.internal_notes],
                    ]}
                />
            ),
        },
        {
            key: 'items',
            label: 'Itens',
            content: (
                <ItemsTable
                    items={order.items}
                    extra={(item) => {
                        const row = item as SaleOrderItem;

                        return (
                            <>
                                {quantity(
                                    row.fulfilled_quantity,
                                    row.unit_code,
                                )}
                                {Number(row.returned_quantity) > 0 && (
                                    <span className="block text-xs text-muted-foreground">
                                        Devolvido:{' '}
                                        {quantity(
                                            row.returned_quantity,
                                            row.unit_code,
                                        )}
                                    </span>
                                )}
                                {!row.controls_stock && (
                                    <span className="block text-xs text-muted-foreground">
                                        Sem controle de estoque
                                    </span>
                                )}
                            </>
                        );
                    }}
                />
            ),
        },
        {
            key: 'fulfillments',
            label: 'Atendimento',
            content: (
                <div className="space-y-4">
                    {order.reserves_stock && isPending && (
                        <p className="text-sm text-muted-foreground">
                            O saldo pendente dos itens com estoque está
                            reservado.
                        </p>
                    )}
                    {!stockEnabled && (
                        <p className="text-sm text-muted-foreground">
                            Estoque não habilitado: os atendimentos não
                            movimentam estoque.
                        </p>
                    )}
                    {fulfillments.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Nenhum atendimento registrado.
                        </p>
                    )}
                    {fulfillments.map((fulfillment) => (
                        <article
                            key={fulfillment.id}
                            className="space-y-2 rounded-lg border p-3"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="font-medium">
                                    Atendimento #{fulfillment.number} ·{' '}
                                    {formatDate(fulfillment.fulfilled_at)} ·{' '}
                                    {fulfillment.method.label}{' '}
                                    <SaleStatusBadge
                                        status={fulfillment.status}
                                    />
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={fulfillmentDocument.url(
                                                fulfillment.id,
                                            )}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Comprovante
                                        </a>
                                    </Button>
                                    {fulfillment.status.value === 'confirmed' &&
                                        can('sales.return') &&
                                        acceptsReturns && (
                                            <SaleReturnDialog
                                                fulfillment={fulfillment}
                                                today={today}
                                                stockEnabled={stockEnabled}
                                            />
                                        )}
                                    {fulfillment.status.value === 'confirmed' &&
                                        can('sales.fulfill') &&
                                        status !== 'completed' &&
                                        !fulfillment.has_returns && (
                                            <ReasonDialog
                                                size="sm"
                                                url={reverse.url(
                                                    fulfillment.id,
                                                )}
                                                title={`Estornar atendimento #${fulfillment.number}`}
                                                description="Estorno técnico de um registro incorreto: desfaz as saídas de estoque e as quantidades atendidas. Para mercadoria que voltou do cliente, registre uma devolução."
                                                confirmLabel="Estornar"
                                            >
                                                Estornar
                                            </ReasonDialog>
                                        )}
                                </div>
                            </div>
                            <ul className="text-sm">
                                {fulfillment.items.map((item) => (
                                    <li key={item.id}>
                                        {item.description}:{' '}
                                        {quantity(
                                            item.quantity,
                                            item.unit_code,
                                        )}
                                        {canViewCost &&
                                            item.cost_total !== undefined && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · custo{' '}
                                                    {item.cost_total === null
                                                        ? 'indisponível'
                                                        : formatMoney(
                                                              item.cost_total,
                                                          )}
                                                </span>
                                            )}
                                    </li>
                                ))}
                            </ul>
                            {fulfillment.reversal_reason && (
                                <p className="text-sm text-muted-foreground">
                                    Estornado por {fulfillment.reversed_by_name}
                                    : {fulfillment.reversal_reason}
                                </p>
                            )}
                        </article>
                    ))}
                </div>
            ),
        },
        {
            key: 'values',
            label: 'Valores',
            content: (
                <div className="space-y-6">
                    <ValuesSection sale={order} />
                    {order.margin && (
                        <section
                            className="space-y-2 rounded-lg border p-4"
                            aria-label="Margem comercial"
                        >
                            <h3 className="font-medium">
                                Margem comercial (atendido menos devolvido)
                            </h3>
                            <Facts
                                items={[
                                    [
                                        'Receita líquida',
                                        formatMoney(order.margin.revenue),
                                    ],
                                    [
                                        'Custo da mercadoria',
                                        order.margin.cost === null
                                            ? 'Indisponível'
                                            : formatMoney(order.margin.cost),
                                    ],
                                    [
                                        'Margem bruta',
                                        order.margin.margin === null
                                            ? 'Indisponível'
                                            : formatMoney(order.margin.margin),
                                    ],
                                    [
                                        'Margem %',
                                        order.margin.percent === null
                                            ? 'Indisponível'
                                            : formatPercentage(
                                                  order.margin.percent,
                                              ),
                                    ],
                                ]}
                            />
                            {!order.margin.is_complete && (
                                <p className="text-xs text-muted-foreground">
                                    Há itens atendidos sem custo confiável; a
                                    margem não é estimada.
                                </p>
                            )}
                        </section>
                    )}
                </div>
            ),
        },
        {
            key: 'payment',
            label: 'Pagamento',
            content: <PaymentSection sale={order} />,
        },
        {
            key: 'returns',
            label: 'Devoluções',
            content: (
                <div className="space-y-3">
                    {returns.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Nenhuma devolução registrada.
                        </p>
                    )}
                    {returns.map((saleReturn) => (
                        <article
                            key={saleReturn.id}
                            className="space-y-1 rounded-lg border p-3 text-sm"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="font-medium">
                                    Devolução #{saleReturn.number} ·{' '}
                                    {formatDate(saleReturn.returned_at)} ·
                                    atendimento #{saleReturn.fulfillment_number}{' '}
                                    · {formatMoney(saleReturn.total_value)}
                                </p>
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={returnDocument.url(saleReturn.id)}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        PDF
                                    </a>
                                </Button>
                            </div>
                            <p>{saleReturn.reason}</p>
                            <ul className="text-muted-foreground">
                                {saleReturn.items.map((item, index) => (
                                    <li key={index}>
                                        {item.description}:{' '}
                                        {quantity(
                                            item.quantity,
                                            item.unit_code,
                                        )}{' '}
                                        · {formatMoney(item.historical_value)}
                                        {item.restocked
                                            ? ' · retornou ao estoque'
                                            : ''}
                                    </li>
                                ))}
                            </ul>
                        </article>
                    ))}
                </div>
            ),
        },
        {
            key: 'history',
            label: 'Histórico',
            content: <HistorySection history={order.history} />,
        },
    ];

    return (
        <>
            <Head title={`Venda #${order.number}`} />
            <div className="mx-auto max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-2">
                        <Heading
                            variant="small"
                            title={`Venda #${order.number}`}
                            description={`${snapshot?.name ?? 'Consumidor não identificado'} · ${formatMoney(order.total)}`}
                        />
                        <SaleStatusBadge status={order.status} />
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
                        {status === 'draft' && can('sales.update') && (
                            <Button variant="outline" asChild>
                                <Link href={edit(order.id)}>Editar</Link>
                            </Button>
                        )}
                        {status === 'draft' && can('sales.confirm') && (
                            <ConfirmButton
                                route={{
                                    url: confirm.url(order.id),
                                    method: 'post',
                                }}
                                title="Confirmar venda"
                                description={
                                    stockEnabled
                                        ? 'Os itens com estoque controlado serão reservados.'
                                        : 'A venda passa a valer comercialmente.'
                                }
                                confirmLabel="Confirmar"
                            >
                                Confirmar
                            </ConfirmButton>
                        )}
                        {isPending && can('sales.fulfill') && (
                            <FulfillDialog
                                order={order}
                                methods={DELIVERY_METHODS}
                                today={today}
                                stockEnabled={stockEnabled}
                            />
                        )}
                        {status === 'fulfilled' && can('sales.confirm') && (
                            <ConfirmButton
                                route={{
                                    url: complete.url(order.id),
                                    method: 'post',
                                }}
                                title="Concluir venda"
                                description="Encerra a venda comercialmente. Não registra recebimento: a situação financeira é controlada pelo Financeiro."
                                confirmLabel="Concluir"
                            >
                                Concluir
                            </ConfirmButton>
                        )}
                        {status === 'partially_fulfilled' &&
                            can('sales.cancel') && (
                                <ReasonDialog
                                    url={closeBalance.url(order.id)}
                                    title="Encerrar saldo não atendido"
                                    description="Os itens pendentes deixam de ser atendidos e as reservas são liberadas."
                                    confirmLabel="Encerrar saldo"
                                >
                                    Encerrar saldo
                                </ReasonDialog>
                            )}
                        {['draft', 'confirmed'].includes(status) &&
                            can('sales.cancel') && (
                                <ReasonDialog
                                    url={cancel.url(order.id)}
                                    title="Cancelar venda"
                                    description="As reservas de estoque são liberadas. A venda cancelada não pode ser reaberta."
                                    confirmLabel="Cancelar venda"
                                >
                                    Cancelar
                                </ReasonDialog>
                            )}
                    </div>
                </div>
                <Tabs label="Ficha da venda" tabs={tabs} />
                <OriginChargesSection
                    data={paymentCharges}
                    isOpen={status !== 'draft' && status !== 'cancelled'}
                />
                <OriginFiscalDocumentsSection
                    data={fiscalDocuments}
                    canCreate={status !== 'draft' && status !== 'cancelled'}
                />
            </div>
        </>
    );
}

SaleOrderShow.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Pedidos de venda', href: index() },
        { title: 'Venda', href: '#' },
    ],
};
