import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { ChargeStatusBadge } from '@/components/payments/charge-status';
import { paymentsBreadcrumb } from '@/components/payments/payments-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { formatMoney } from '@/lib/decimal';
import { show } from '@/routes/payments/charges';
import { index, run } from '@/routes/payments/reconciliation';
import { reprocess } from '@/routes/payments/webhook-events';
import type {
    Paginated,
    PaymentCharge,
    PaymentWebhookEventData,
} from '@/types';

function ChargeList({
    charges,
    empty,
}: {
    charges: PaymentCharge[];
    empty: string;
}) {
    const { formatDateTime } = useTenant();

    return (
        <ul className="divide-y rounded-lg border">
            {charges.length === 0 && (
                <li className="p-4 text-sm text-muted-foreground">{empty}</li>
            )}
            {charges.map((charge) => (
                <li
                    key={charge.id}
                    className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                >
                    <Link
                        href={show(charge.id)}
                        className="underline-offset-4 hover:underline"
                    >
                        #{charge.number} · {charge.description}
                        <span className="block text-xs text-muted-foreground">
                            Última consulta:{' '}
                            {charge.last_synced_at
                                ? formatDateTime(charge.last_synced_at)
                                : 'nunca'}
                        </span>
                    </Link>
                    <span className="flex items-center gap-2">
                        {formatMoney(charge.amount)}
                        <ChargeStatusBadge
                            status={charge.status}
                            label={charge.status_label}
                        />
                    </span>
                </li>
            ))}
        </ul>
    );
}

export default function PaymentReconciliation({
    events,
    open,
    unmatched,
    failedCount,
}: {
    events: Paginated<PaymentWebhookEventData>;
    open: PaymentCharge[];
    unmatched: PaymentCharge[];
    failedCount: number;
}) {
    const { can, formatDateTime } = useTenant();

    return (
        <>
            <Head title="Conciliação de pagamentos" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Conciliação"
                        description="Confere as cobranças com o provedor. A conciliação automática roda a cada 15 minutos; use o botão para conciliar agora."
                    />
                    <Button
                        onClick={() =>
                            router.post(run.url(), {}, { preserveScroll: true })
                        }
                    >
                        Conciliar agora
                    </Button>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section
                        aria-label="Cobranças em aberto"
                        className="space-y-2"
                    >
                        <h3 className="font-medium">Cobranças em aberto</h3>
                        <ChargeList
                            charges={open}
                            empty="Nenhuma cobrança em aberto."
                        />
                    </section>
                    <section
                        aria-label="Pix do PDV sem venda"
                        className="space-y-2"
                    >
                        <h3 className="font-medium">
                            Pix do PDV pagos sem venda concluída
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            Pagamentos confirmados depois de o atendimento ser
                            abandonado. Verifique com o cliente e, se for o
                            caso, devolva o valor na cobrança.
                        </p>
                        <ChargeList
                            charges={unmatched}
                            empty="Nenhum pagamento pendente de conferência."
                        />
                    </section>
                </div>

                <section aria-label="Eventos de webhook" className="space-y-3">
                    <h3 className="flex items-center gap-2 font-medium">
                        Notificações recebidas
                        {failedCount > 0 && (
                            <Badge variant="destructive">
                                {failedCount} com falha
                            </Badge>
                        )}
                    </h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Recebida em</th>
                                    <th className="p-3">Provedor</th>
                                    <th className="p-3">Situação</th>
                                    <th className="p-3 text-right">
                                        Pagamentos citados
                                    </th>
                                    <th className="p-3">Detalhe</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {events.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            Nenhuma notificação recebida.
                                        </td>
                                    </tr>
                                )}
                                {events.data.map((event) => (
                                    <tr
                                        key={event.id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3 whitespace-nowrap">
                                            {event.created_at
                                                ? formatDateTime(
                                                      event.created_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="p-3">
                                            {event.provider}
                                        </td>
                                        <td className="p-3">
                                            <Badge
                                                variant={
                                                    event.status === 'failed'
                                                        ? 'destructive'
                                                        : event.status ===
                                                            'processed'
                                                          ? 'secondary'
                                                          : 'outline'
                                                }
                                            >
                                                {event.status_label}
                                            </Badge>
                                            <span className="block text-xs text-muted-foreground">
                                                {event.attempts} tentativa(s)
                                            </span>
                                        </td>
                                        <td className="p-3 text-right">
                                            {event.charges}
                                        </td>
                                        <td className="p-3 text-xs">
                                            {event.error ?? '—'}
                                        </td>
                                        <td className="p-3 text-right">
                                            {can('payments.reprocess') &&
                                                (event.status === 'failed' ||
                                                    event.status ===
                                                        'ignored') && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(
                                                                reprocess.url(
                                                                    event.id,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Reprocessar
                                                    </Button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={events.links} />
                </section>
            </div>
        </>
    );
}

PaymentReconciliation.layout = {
    breadcrumbs: [paymentsBreadcrumb, { title: 'Conciliação', href: index() }],
};
