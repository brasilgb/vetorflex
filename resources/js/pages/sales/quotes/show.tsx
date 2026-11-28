import { Head, Link, router } from '@inertiajs/react';
import ConfirmButton from '@/components/confirm-button';
import Heading from '@/components/heading';
import ReasonDialog from '@/components/reason-dialog';
import {
    Facts,
    HistorySection,
    ItemsTable,
    PaymentSection,
    ValuesSection,
} from '@/components/sales/sale-sections';
import SaleStatusBadge from '@/components/sales/status-badge';
import type { Tab } from '@/components/tabs';
import Tabs from '@/components/tabs';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { dashboard } from '@/routes/sales';
import { show as orderShow } from '@/routes/sales/orders';
import {
    approve,
    cancel,
    convert,
    document,
    edit,
    index,
    issue,
    reject,
} from '@/routes/sales/quotes';
import type { SaleQuote } from '@/types';

export default function QuoteShow({
    quote,
}: {
    quote: SaleQuote;
    today: string;
}) {
    const { can } = useTenant();
    const status = quote.status.value;
    const snapshot = quote.customer_snapshot;
    const canManage = can('sales.manage_quotes');
    const canDecide = can('sales.approve_quotes');

    const tabs: Tab[] = [
        {
            key: 'general',
            label: 'Dados gerais',
            content: (
                <div className="space-y-4">
                    <Facts
                        items={[
                            [
                                'Cliente',
                                snapshot?.name ?? 'Consumidor não identificado',
                            ],
                            ['CPF/CNPJ', snapshot?.document],
                            ['Contato', snapshot?.contact ?? snapshot?.phone],
                            ['Responsável', quote.seller_name],
                            ['Emissão', formatDate(quote.issue_date)],
                            ['Validade', formatDate(quote.valid_until)],
                            [
                                'Venda gerada',
                                quote.order ? (
                                    <Link
                                        className="underline"
                                        href={orderShow(quote.order.id)}
                                    >
                                        #{quote.order.number}
                                    </Link>
                                ) : null,
                            ],
                            ['Condições comerciais', quote.commercial_terms],
                            ['Observações', quote.notes],
                        ]}
                    />
                </div>
            ),
        },
        {
            key: 'items',
            label: 'Itens',
            content: <ItemsTable items={quote.items} />,
        },
        {
            key: 'values',
            label: 'Valores',
            content: <ValuesSection sale={quote} />,
        },
        {
            key: 'payment',
            label: 'Pagamento',
            content: <PaymentSection sale={quote} />,
        },
        {
            key: 'history',
            label: 'Histórico',
            content: <HistorySection history={quote.history} />,
        },
    ];

    return (
        <>
            <Head title={`Orçamento #${quote.number}`} />
            <div className="mx-auto max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-2">
                        <Heading
                            variant="small"
                            title={`Orçamento #${quote.number}`}
                            description={
                                snapshot?.name ?? 'Consumidor não identificado'
                            }
                        />
                        <SaleStatusBadge
                            status={quote.status}
                            isOverdue={quote.is_overdue}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a
                                href={document.url(quote.id)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                PDF
                            </a>
                        </Button>
                        {canManage && status === 'draft' && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={edit(quote.id)}>Editar</Link>
                                </Button>
                                <Button
                                    onClick={() =>
                                        router.post(
                                            issue.url(quote.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Emitir
                                </Button>
                            </>
                        )}
                        {canDecide && status === 'issued' && (
                            <>
                                <ConfirmButton
                                    route={{
                                        url: approve.url(quote.id),
                                        method: 'post',
                                    }}
                                    title="Aprovar orçamento"
                                    description="O cliente aceitou a proposta. Depois de aprovado, o orçamento pode ser convertido em venda."
                                    confirmLabel="Aprovar"
                                >
                                    Aprovar
                                </ConfirmButton>
                                <ReasonDialog
                                    url={reject.url(quote.id)}
                                    title="Recusar orçamento"
                                    description="Informe o motivo da recusa."
                                    confirmLabel="Recusar"
                                >
                                    Recusar
                                </ReasonDialog>
                            </>
                        )}
                        {canManage &&
                            can('sales.create') &&
                            status === 'approved' && (
                                <Button
                                    onClick={() =>
                                        router.post(convert.url(quote.id))
                                    }
                                >
                                    Converter em venda
                                </Button>
                            )}
                        {canManage &&
                            ['draft', 'issued', 'approved'].includes(
                                status,
                            ) && (
                                <ReasonDialog
                                    url={cancel.url(quote.id)}
                                    title="Cancelar orçamento"
                                    description="O orçamento cancelado não pode ser reaberto."
                                    confirmLabel="Cancelar orçamento"
                                >
                                    Cancelar
                                </ReasonDialog>
                            )}
                    </div>
                </div>
                <Tabs label="Ficha do orçamento" tabs={tabs} />
            </div>
        </>
    );
}

QuoteShow.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Orçamentos', href: index() },
        { title: 'Orçamento', href: '#' },
    ],
};
