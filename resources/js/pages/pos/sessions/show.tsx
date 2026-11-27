import { HttpNetworkError } from '@inertiajs/core';
import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeTextarea from '@/components/native-textarea';
import AuthorizationFields, {
    type Credentials,
    credentialsPayload,
    emptyCredentials,
} from '@/components/pos/authorization-fields';
import { posBreadcrumb } from '@/components/pos/pos-nav';
import { Badge } from '@/components/ui/badge';
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
import { useTenant } from '@/hooks/use-tenant';
import { formatMoney, parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { register } from '@/routes/pos';
import { store as storeMovement } from '@/routes/pos/cash-movements';
import { show as saleShow } from '@/routes/pos/sales';
import { close, index } from '@/routes/pos/sessions';
import type {
    PosMovement,
    PosRefundData,
    PosSaleData,
    PosSessionData,
    PosSessionSummary,
} from '@/types';

function isZero(value: string | null | undefined): boolean {
    return value === null || value === undefined || /^-?0+(\.0+)?$/.test(value);
}

function CloseDialog({
    session,
    isOwnSession,
    expected,
}: {
    session: PosSessionData;
    isOwnSession: boolean;
    expected: string | undefined;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [credentials, setCredentials] =
        useState<Credentials>(emptyCredentials);
    const form = useForm({ counted_cash: '', closing_notes: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.reset();
                form.clearErrors();
                setCredentials(emptyCredentials);
            }}
        >
            <DialogTrigger asChild>
                <Button>Fechar caixa</Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            counted_cash:
                                parseDecimal(data.counted_cash) || '0',
                            authorization: credentialsPayload(credentials),
                        }));
                        form.post(close.url(session.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>
                        Fechar caixa — {session.terminal.name}
                    </DialogTitle>
                    <DialogDescription>
                        {expected === undefined
                            ? 'Fechamento cego: conte o dinheiro da gaveta e informe o valor. O esperado aparece depois do fechamento.'
                            : `Dinheiro esperado na gaveta: ${formatMoney(expected)}. Depois de fechado, o caixa só aceita correções autorizadas.`}
                    </DialogDescription>
                    <FormField
                        id="counted-cash"
                        label="Dinheiro contado"
                        error={form.errors.counted_cash}
                    >
                        <MoneyInput
                            id="counted-cash"
                            required
                            autoFocus
                            value={form.data.counted_cash}
                            onChange={(event) =>
                                form.setData('counted_cash', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="closing-notes"
                        label="Justificativa (obrigatória se houver divergência)"
                        error={form.errors.closing_notes}
                    >
                        <NativeTextarea
                            id="closing-notes"
                            maxLength={1000}
                            value={form.data.closing_notes}
                            onChange={(event) =>
                                form.setData(
                                    'closing_notes',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                    {(!isOwnSession || errors.authorization) && (
                        <AuthorizationFields
                            id="close-authorization"
                            value={credentials}
                            onChange={setCredentials}
                            error={errors.authorization}
                        />
                    )}
                    {errors.session && (
                        <p className="text-sm text-destructive">
                            {errors.session}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Voltar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>
                            Confirmar fechamento
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Correção depois do fechamento: ajuste autorizado, positivo ou negativo, registrado à parte do resumo original. */
function CorrectionDialog({ sessionId }: { sessionId: number }) {
    const [isOpen, setIsOpen] = useState(false);
    const [failure, setFailure] = useState<string>();
    const http = useHttp<
        {
            type: string;
            amount: string;
            reason: string;
            idempotency_key: string;
            authorization: Credentials | null;
        },
        Record<string, unknown>
    >({
        type: 'adjustment',
        amount: '',
        reason: '',
        idempotency_key: uuid(),
        authorization: null,
    });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                http.reset();
                http.clearErrors();
                http.setData('idempotency_key', uuid());
                setFailure(undefined);
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">Registrar correção</Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setFailure(undefined);
                        http.transform((data) => ({
                            ...data,
                            amount: parseDecimal(data.amount),
                        }));
                        http.post(storeMovement.url(sessionId), {
                            onSuccess: () => {
                                setIsOpen(false);
                                router.reload();
                            },
                        }).catch((error: unknown) =>
                            setFailure(
                                error instanceof HttpNetworkError
                                    ? 'Sem conexão: a correção não foi registrada.'
                                    : 'Não foi possível registrar a correção.',
                            ),
                        );
                    }}
                >
                    <DialogTitle>Correção após o fechamento</DialogTitle>
                    <DialogDescription>
                        O fechamento original é preservado. A correção fica
                        registrada com motivo e responsável; use valor negativo
                        para reduzir o dinheiro apurado.
                    </DialogDescription>
                    <FormField
                        id="correction-amount"
                        label="Valor (positivo ou negativo)"
                        error={http.errors.amount ?? http.errors.type}
                    >
                        <MoneyInput
                            id="correction-amount"
                            required
                            value={http.data.amount}
                            onChange={(event) =>
                                http.setData('amount', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="correction-reason"
                        label="Motivo"
                        error={http.errors.reason}
                    >
                        <Input
                            id="correction-reason"
                            required
                            maxLength={400}
                            value={http.data.reason}
                            onChange={(event) =>
                                http.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    {(failure || http.errors.authorization) && (
                        <p className="text-sm text-destructive">
                            {failure ?? http.errors.authorization}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Voltar
                            </Button>
                        </DialogClose>
                        <Button disabled={http.processing}>Registrar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone?: 'alert';
}) {
    return (
        <div className="rounded-lg border p-3">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={`text-lg font-semibold ${tone === 'alert' ? 'text-destructive' : ''}`}
            >
                {value}
            </dd>
        </div>
    );
}

export default function PosSessionShow({
    session,
    summary,
    hidesExpected,
    movements,
    sales,
    refunds,
    canClose,
    canCorrect,
    isOwnSession,
}: {
    session: PosSessionData;
    summary: PosSessionSummary;
    hidesExpected: boolean;
    movements: PosMovement[];
    sales: PosSaleData[];
    refunds: PosRefundData[];
    canClose: boolean;
    canCorrect: boolean;
    isOwnSession: boolean;
}) {
    const { formatDateTime } = useTenant();
    const isOpen = session.status === 'open';

    return (
        <>
            <Head
                title={`Caixa ${session.terminal.name} nº ${session.number}`}
            />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={`${session.terminal.name} · sessão ${session.number}`}
                            description={`Operador: ${session.user_name}. Aberta em ${formatDateTime(session.opened_at)}${session.closed_at ? `, fechada em ${formatDateTime(session.closed_at)} por ${session.closed_by_name ?? '—'}` : ''}.`}
                        />
                        <div className="flex flex-wrap gap-1">
                            <Badge variant={isOpen ? 'default' : 'secondary'}>
                                {session.status_label}
                            </Badge>
                            {session.is_blind && (
                                <Badge variant="outline">Fechamento cego</Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {isOpen && isOwnSession && (
                            <Button variant="outline" asChild>
                                <Link href={register(session.id)}>
                                    Voltar ao caixa
                                </Link>
                            </Button>
                        )}
                        {canClose && (
                            <CloseDialog
                                session={session}
                                isOwnSession={isOwnSession}
                                expected={summary.expected_cash}
                            />
                        )}
                        {canCorrect && (
                            <CorrectionDialog sessionId={session.id} />
                        )}
                    </div>
                </div>

                <section aria-label="Resumo do caixa" className="space-y-3">
                    <h3 className="font-medium">Resumo</h3>
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat
                            label="Saldo inicial"
                            value={formatMoney(summary.opening)}
                        />
                        <Stat
                            label={`Vendas (${summary.sales_count})`}
                            value={formatMoney(summary.sales_total)}
                        />
                        <Stat
                            label="Recebido (informado)"
                            value={formatMoney(summary.received_total)}
                        />
                        <Stat
                            label="A prazo"
                            value={formatMoney(summary.term_total)}
                        />
                        <Stat
                            label="Troco entregue"
                            value={formatMoney(summary.change_total)}
                        />
                        <Stat
                            label="Suprimentos"
                            value={formatMoney(summary.supplies)}
                        />
                        <Stat
                            label="Sangrias"
                            value={formatMoney(summary.withdrawals)}
                        />
                        <Stat
                            label="Ajustes"
                            value={formatMoney(summary.adjustments)}
                        />
                        <Stat
                            label={`Devoluções (${summary.returns_count})`}
                            value={formatMoney(summary.refunds_total)}
                        />
                        <Stat
                            label="Restituído em dinheiro"
                            value={formatMoney(summary.cash_refunds)}
                        />
                        <Stat
                            label="Cancelamentos"
                            value={String(summary.cancelled_count)}
                        />
                        <Stat
                            label="Dinheiro recebido"
                            value={formatMoney(summary.cash_received)}
                        />
                        {hidesExpected ? (
                            <Stat
                                label="Dinheiro esperado"
                                value="Oculto (fechamento cego)"
                            />
                        ) : (
                            <Stat
                                label="Dinheiro esperado"
                                value={formatMoney(summary.expected_cash)}
                            />
                        )}
                        {!isOpen && (
                            <>
                                <Stat
                                    label="Dinheiro contado"
                                    value={formatMoney(session.counted_cash)}
                                />
                                <Stat
                                    label="Divergência"
                                    value={formatMoney(session.divergence)}
                                    tone={
                                        isZero(session.divergence)
                                            ? undefined
                                            : 'alert'
                                    }
                                />
                            </>
                        )}
                        {!isZero(summary.post_close_adjustments) && (
                            <Stat
                                label="Correções após o fechamento"
                                value={formatMoney(
                                    summary.post_close_adjustments,
                                )}
                            />
                        )}
                    </dl>
                    {session.closing_notes && (
                        <p className="text-sm">
                            <span className="text-muted-foreground">
                                Justificativa:{' '}
                            </span>
                            {session.closing_notes}
                        </p>
                    )}
                    {session.opening_notes && (
                        <p className="text-sm">
                            <span className="text-muted-foreground">
                                Observações da abertura:{' '}
                            </span>
                            {session.opening_notes}
                        </p>
                    )}
                </section>

                <section
                    aria-label="Recebimentos por forma de pagamento"
                    className="space-y-3"
                >
                    <h3 className="font-medium">
                        Recebimentos por forma de pagamento
                    </h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Forma</th>
                                    <th className="p-3 text-right">
                                        Recebido (informado)
                                    </th>
                                    <th className="p-3 text-right">A prazo</th>
                                </tr>
                            </thead>
                            <tbody>
                                {summary.by_method.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            Nenhum pagamento nesta sessão.
                                        </td>
                                    </tr>
                                )}
                                {summary.by_method.map((method) => (
                                    <tr
                                        key={method.method}
                                        className="border-t"
                                    >
                                        <td className="p-3">{method.label}</td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(method.received)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(method.term)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Pix, cartão e boleto são registrados pelo operador, sem
                        confirmação bancária.
                    </p>
                </section>

                <section
                    aria-label="Movimentações de caixa"
                    className="space-y-3"
                >
                    <h3 className="font-medium">
                        Sangrias, suprimentos e ajustes
                    </h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Data</th>
                                    <th className="p-3">Operação</th>
                                    <th className="p-3">Motivo</th>
                                    <th className="p-3">Responsável</th>
                                    <th className="p-3 text-right">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                {movements.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            Nenhuma movimentação.
                                        </td>
                                    </tr>
                                )}
                                {movements.map((movement) => (
                                    <tr
                                        key={movement.id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3 whitespace-nowrap">
                                            {formatDateTime(
                                                movement.occurred_at,
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {movement.type_label}
                                            {movement.is_post_close && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Após o fechamento
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {movement.reason}
                                        </td>
                                        <td className="p-3">
                                            {movement.user_name}
                                            {movement.authorized_by_name &&
                                                movement.authorized_by_name !==
                                                    movement.user_name && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        Autorizado por{' '}
                                                        {
                                                            movement.authorized_by_name
                                                        }
                                                    </span>
                                                )}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(movement.amount)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section aria-label="Vendas da sessão" className="space-y-3">
                    <h3 className="font-medium">Vendas</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Venda</th>
                                    <th className="p-3">Cliente</th>
                                    <th className="p-3">Situação</th>
                                    <th className="p-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sales.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            Nenhuma venda nesta sessão.
                                        </td>
                                    </tr>
                                )}
                                {sales.map((sale) => (
                                    <tr
                                        key={sale.id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3">
                                            <Link
                                                className="font-medium underline-offset-4 hover:underline"
                                                href={saleShow(sale.id)}
                                            >
                                                {sale.order_number
                                                    ? `#${sale.order_number}`
                                                    : (sale.label ??
                                                      `PDV ${sale.id}`)}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    sale.completed_at ??
                                                        sale.cancelled_at ??
                                                        sale.suspended_at ??
                                                        session.opened_at,
                                                )}
                                            </span>
                                        </td>
                                        <td className="p-3">
                                            {sale.customer_name ?? 'Consumidor'}
                                        </td>
                                        <td className="p-3">
                                            {sale.status_label}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(sale.total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {refunds.length > 0 && (
                    <section aria-label="Devoluções" className="space-y-3">
                        <h3 className="font-medium">Devoluções</h3>
                        <ul className="space-y-2 text-sm">
                            {refunds.map((refund) => (
                                <li
                                    key={refund.id}
                                    className="rounded-lg border p-3"
                                >
                                    Devolução #{refund.return_number}:{' '}
                                    {formatMoney(refund.return_value)} ·
                                    restituído {formatMoney(refund.amount)} em{' '}
                                    {refund.method_label} por {refund.user_name}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

PosSessionShow.layout = {
    breadcrumbs: [
        posBreadcrumb,
        { title: 'Sessões', href: index() },
        { title: 'Sessão', href: '#' },
    ],
};
