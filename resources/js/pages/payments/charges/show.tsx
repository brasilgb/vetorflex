import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import {
    ChargeStatusBadge,
    EnvironmentBadge,
} from '@/components/payments/charge-status';
import { paymentsBreadcrumb } from '@/components/payments/payments-nav';
import ReasonDialog from '@/components/reason-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { cancel, index, issue, sync } from '@/routes/payments/charges';
import { store as storeRefund } from '@/routes/payments/charges/refunds';
import type {
    PaymentCharge,
    PaymentChargeEventData,
    PaymentPix,
    PaymentRefundData,
    PaymentTransaction,
} from '@/types';

function RefundDialog({
    charge,
    transaction,
}: {
    charge: PaymentCharge;
    transaction: PaymentTransaction;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        amount: toDecimalInput(transaction.refundable, 2),
        reason: '',
        idempotency_key: uuid(),
    };
    const form = useForm(initial);

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData({ ...initial, idempotency_key: uuid() });
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Devolver
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            payment_transaction_id: transaction.id,
                            amount: parseDecimal(data.amount),
                        }));
                        form.post(storeRefund.url(charge.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Devolver pagamento</DialogTitle>
                    <DialogDescription>
                        A devolução é solicitada ao provedor e só é considerada
                        feita depois da confirmação dele. Disponível:{' '}
                        {formatMoney(transaction.refundable)}.
                    </DialogDescription>
                    <FormField
                        id="refund-amount"
                        label="Valor"
                        error={form.errors.amount}
                    >
                        <MoneyInput
                            id="refund-amount"
                            required
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="refund-reason"
                        label="Motivo"
                        error={form.errors.reason}
                    >
                        <Input
                            id="refund-reason"
                            required
                            maxLength={400}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Voltar
                            </Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Solicitar devolução
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PaymentChargeShow({
    charge,
    pix,
    transactions,
    refunds,
    events,
    abilities,
}: {
    charge: PaymentCharge;
    pix: PaymentPix;
    transactions: PaymentTransaction[];
    refunds: PaymentRefundData[];
    events: PaymentChargeEventData[];
    abilities: {
        issue: boolean;
        sync: boolean;
        cancel: boolean;
        refund: boolean;
    };
}) {
    const { formatDateTime } = useTenant();
    const [copied, copy] = useClipboard();
    const showsPix =
        pix.pix_copy_paste !== null &&
        ['pending', 'processing', 'overdue', 'partially_paid'].includes(
            charge.status,
        );

    return (
        <>
            <Head title={`Cobrança #${charge.number}`} />
            <div className="space-y-6 p-4 md:p-6 print:p-0">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={`Cobrança #${charge.number}`}
                            description={charge.description}
                        />
                        <div className="flex flex-wrap items-center gap-2">
                            <ChargeStatusBadge
                                status={charge.status}
                                label={charge.status_label}
                            />
                            <EnvironmentBadge
                                environment={charge.environment}
                            />
                            {charge.provider_name && (
                                <span className="text-xs text-muted-foreground">
                                    {charge.provider_name}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2 print:hidden">
                        {abilities.issue && (
                            <Button
                                onClick={() =>
                                    router.post(
                                        issue.url(charge.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Emitir no provedor
                            </Button>
                        )}
                        {abilities.sync && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        sync.url(charge.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Consultar situação
                            </Button>
                        )}
                        {showsPix && (
                            <Button
                                variant="outline"
                                onClick={() => window.print()}
                            >
                                Segunda via (imprimir)
                            </Button>
                        )}
                        {abilities.cancel && (
                            <ReasonDialog
                                url={cancel.url(charge.id)}
                                title="Cancelar cobrança"
                                description="A cobrança é removida no provedor e não poderá mais ser paga."
                                confirmLabel="Cancelar cobrança"
                                variant="outline"
                            >
                                Cancelar
                            </ReasonDialog>
                        )}
                    </div>
                </div>

                {charge.failure_reason && charge.status === 'failed' && (
                    <p className="rounded-lg border border-destructive/40 p-3 text-sm">
                        O provedor recusou a emissão: {charge.failure_reason}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                    <dl className="grid content-start gap-3 sm:grid-cols-2">
                        {[
                            ['Valor', formatMoney(charge.amount)],
                            ['Recebido', formatMoney(charge.paid_amount)],
                            ['Em aberto', formatMoney(charge.open_amount)],
                            ['Devolvido', formatMoney(charge.refunded_amount)],
                            ['Vencimento', formatDate(charge.due_date)],
                            [
                                'Validade do Pix',
                                charge.expires_at
                                    ? formatDateTime(charge.expires_at)
                                    : '—',
                            ],
                            [
                                'Pagador',
                                charge.payer_name ?? 'Não identificado',
                            ],
                            [
                                'Origem',
                                charge.origin_reference
                                    ? `${charge.origin_label} — ${charge.origin_reference}`
                                    : charge.origin_label,
                            ],
                            ['Referência interna', charge.reference ?? '—'],
                            [
                                'Identificador no provedor',
                                charge.provider_charge_id ?? '—',
                            ],
                            ...(charge.fee_amount !== null
                                ? [
                                      [
                                          'Taxas informadas',
                                          formatMoney(charge.fee_amount),
                                      ],
                                      [
                                          'Valor líquido',
                                          formatMoney(charge.net_amount),
                                      ],
                                  ]
                                : []),
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-lg border p-3">
                                <dt className="text-sm text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="font-medium break-all">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {showsPix && (
                        <section
                            aria-label="Pagamento por Pix"
                            className="space-y-3 rounded-lg border p-4 text-center"
                        >
                            {pix.qr_code_image && (
                                <img
                                    src={pix.qr_code_image}
                                    alt="QR Code Pix da cobrança"
                                    className="mx-auto w-56 rounded bg-white p-2"
                                />
                            )}
                            <p className="text-sm text-muted-foreground">
                                Escaneie o QR Code ou use o código copia e cola
                                no aplicativo do banco.
                            </p>
                            <code className="block max-h-24 overflow-y-auto rounded bg-muted p-2 text-left text-xs break-all">
                                {pix.pix_copy_paste}
                            </code>
                            <div className="flex flex-wrap justify-center gap-2 print:hidden">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        copy(pix.pix_copy_paste ?? '')
                                    }
                                >
                                    {copied !== null &&
                                    copied === pix.pix_copy_paste
                                        ? 'Código copiado'
                                        : 'Copiar código'}
                                </Button>
                                {pix.payment_url && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            copy(pix.payment_url ?? '')
                                        }
                                    >
                                        {copied !== null &&
                                        copied === pix.payment_url
                                            ? 'Link copiado'
                                            : 'Copiar link de pagamento'}
                                    </Button>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                O pagamento só aparece como recebido depois da
                                confirmação do provedor.
                            </p>
                        </section>
                    )}
                </div>

                {transactions.length > 0 && (
                    <section
                        aria-label="Pagamentos recebidos"
                        className="space-y-3 print:hidden"
                    >
                        <h3 className="font-medium">Pagamentos recebidos</h3>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Data</th>
                                        <th className="p-3">Identificador</th>
                                        <th className="p-3">Pagador</th>
                                        <th className="p-3 text-right">
                                            Valor
                                        </th>
                                        <th className="p-3 text-right">
                                            Devolvido
                                        </th>
                                        {abilities.refund && (
                                            <th className="p-3" />
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.map((transaction) => (
                                        <tr
                                            key={transaction.id}
                                            className="border-t"
                                        >
                                            <td className="p-3 whitespace-nowrap">
                                                {formatDateTime(
                                                    transaction.paid_at,
                                                )}
                                            </td>
                                            <td className="p-3 text-xs break-all">
                                                {
                                                    transaction.provider_transaction_id
                                                }
                                            </td>
                                            <td className="p-3">
                                                {transaction.payer_name ?? '—'}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {formatMoney(
                                                    transaction.amount,
                                                )}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {formatMoney(
                                                    transaction.refunded_amount,
                                                )}
                                            </td>
                                            {abilities.refund && (
                                                <td className="p-3 text-right">
                                                    {transaction.refundable !==
                                                        '0.00' && (
                                                        <RefundDialog
                                                            charge={charge}
                                                            transaction={
                                                                transaction
                                                            }
                                                        />
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {refunds.length > 0 && (
                    <section
                        aria-label="Devoluções"
                        className="space-y-2 print:hidden"
                    >
                        <h3 className="font-medium">Devoluções</h3>
                        <ul className="space-y-2 text-sm">
                            {refunds.map((refund) => (
                                <li
                                    key={refund.id}
                                    className="rounded-lg border p-3"
                                >
                                    {formatMoney(refund.amount)} ·{' '}
                                    {refund.status_label} · {refund.reason}
                                    <span className="block text-xs text-muted-foreground">
                                        {refund.requested_by_name ?? '—'}
                                        {refund.created_at
                                            ? ` · ${formatDateTime(refund.created_at)}`
                                            : ''}
                                        {refund.failure_reason
                                            ? ` · ${refund.failure_reason}`
                                            : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section
                    aria-label="Histórico"
                    className="space-y-2 print:hidden"
                >
                    <h3 className="font-medium">Histórico</h3>
                    <ol className="space-y-1 text-sm">
                        {events.map((event) => (
                            <li
                                key={event.id}
                                className="flex flex-wrap justify-between gap-2 rounded-md border px-3 py-2"
                            >
                                <span>{event.description}</span>
                                <span className="text-xs text-muted-foreground">
                                    {event.user_name} ·{' '}
                                    {event.created_at
                                        ? formatDateTime(event.created_at)
                                        : ''}
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </>
    );
}

PaymentChargeShow.layout = {
    breadcrumbs: [
        paymentsBreadcrumb,
        { title: 'Cobranças', href: index() },
        { title: 'Cobrança', href: '#' },
    ],
};
