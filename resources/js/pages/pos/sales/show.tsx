import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import OriginFiscalDocumentsSection, {
    type OriginFiscalDocuments,
} from '@/components/fiscal/origin-fiscal-documents';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
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
import {
    formatDecimal,
    formatMoney,
    parseDecimal,
    toDecimalInput,
} from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { show as orderShow } from '@/routes/sales/orders';
import { index, pdf, receipt } from '@/routes/pos/sales';
import { store as storeReturn } from '@/routes/pos/sales/returns';
import type { Option, PosRefundData, PosSaleData } from '@/types';

type SaleItem = {
    id: number | null;
    description: string;
    quantity: string;
    unit_code: string;
    unit_price: string | null;
    discount: string;
    total: string | null;
    returnable: string;
    allows_fraction: boolean;
};

type SalePayment = {
    sequence: number;
    method: string;
    method_label: string;
    amount: string;
    tendered: string | null;
    change: string | null;
    is_received: boolean;
    due_date: string;
};

function isZero(value: string | null | undefined): boolean {
    return value === null || value === undefined || /^-?0*(\.0+)?$/.test(value);
}

function ReturnDialog({
    sale,
    items,
    refundMethods,
    openSession,
    canReturn,
}: {
    sale: PosSaleData;
    items: SaleItem[];
    refundMethods: Option[];
    openSession: number | null;
    canReturn: boolean;
}) {
    const { can } = useTenant();
    const returnable = items.filter(
        (item) => item.id !== null && !isZero(item.returnable),
    );
    const [isOpen, setIsOpen] = useState(false);
    const [credentials, setCredentials] =
        useState<Credentials>(emptyCredentials);
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const initial = {
        restock: true,
        reason: '',
        refund_method:
            openSession !== null
                ? 'cash'
                : (refundMethods.find((method) => method.value !== 'cash')
                      ?.value ?? 'cash'),
        idempotency_key: uuid(),
    };
    const form = useForm(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const needsAuthorization =
        !can('pos.return') || errors.authorization !== undefined;

    if (!canReturn || returnable.length === 0) {
        return null;
    }

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData({ ...initial, idempotency_key: uuid() });
                form.clearErrors();
                setCredentials(emptyCredentials);
                setQuantities(
                    Object.fromEntries(
                        returnable.map((item) => [
                            item.id as number,
                            toDecimalInput(item.returnable),
                        ]),
                    ),
                );
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">Registrar devolução</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            pos_session_id: openSession,
                            authorization: credentialsPayload(credentials),
                            items: returnable
                                .map((item) => ({
                                    sale_order_item_id: item.id as number,
                                    quantity: parseDecimal(
                                        quantities[item.id as number] ?? '',
                                    ),
                                }))
                                .filter(
                                    (item) =>
                                        item.quantity !== '' &&
                                        !isZero(item.quantity),
                                ),
                        }));
                        form.post(storeReturn.url(sale.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Devolução da venda</DialogTitle>
                    <DialogDescription>
                        Informe as quantidades devolvidas. A restituição ao
                        cliente é calculada pelo servidor sobre o que já foi
                        recebido; em dinheiro, sai da gaveta do seu caixa
                        aberto.
                    </DialogDescription>
                    <div className="space-y-2">
                        {returnable.map((item) => (
                            <div
                                key={item.id}
                                className="grid grid-cols-[1fr_8rem] items-center gap-2"
                            >
                                <label
                                    htmlFor={`return-${item.id}`}
                                    className="text-sm"
                                >
                                    {item.description}
                                    <span className="block text-xs text-muted-foreground">
                                        Disponível:{' '}
                                        {formatDecimal(item.returnable)}{' '}
                                        {item.unit_code}
                                    </span>
                                </label>
                                <Input
                                    id={`return-${item.id}`}
                                    inputMode="decimal"
                                    value={quantities[item.id as number] ?? ''}
                                    onChange={(event) =>
                                        setQuantities({
                                            ...quantities,
                                            [item.id as number]:
                                                event.target.value,
                                        })
                                    }
                                />
                            </div>
                        ))}
                        {errors.items && (
                            <p className="text-sm text-destructive">
                                {errors.items}
                            </p>
                        )}
                    </div>
                    <FormField
                        id="return-reason"
                        label="Motivo"
                        error={form.errors.reason}
                    >
                        <Input
                            id="return-reason"
                            required
                            maxLength={400}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="return-method"
                        label="Forma de restituição"
                        error={form.errors.refund_method}
                        hint={
                            openSession === null
                                ? 'Sem caixa aberto, a restituição em dinheiro não está disponível.'
                                : undefined
                        }
                    >
                        <NativeSelect
                            id="return-method"
                            value={form.data.refund_method}
                            onChange={(event) =>
                                form.setData(
                                    'refund_method',
                                    event.target.value,
                                )
                            }
                        >
                            {refundMethods.map((method) => (
                                <option
                                    key={method.value}
                                    value={method.value}
                                    disabled={
                                        method.value === 'cash' &&
                                        openSession === null
                                    }
                                >
                                    {method.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <CheckboxField
                        id="return-restock"
                        label="Repor os itens ao estoque"
                        checked={form.data.restock}
                        onChange={(checked) => form.setData('restock', checked)}
                    />
                    {needsAuthorization && (
                        <AuthorizationFields
                            id="return-authorization"
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
                            Registrar devolução
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PosSaleShow({
    sale,
    items,
    payments,
    refunds,
    refundMethods,
    openSession,
    salesEnabled,
    canReturn,
    canReprint,
    fiscalDocuments = null,
}: {
    sale: PosSaleData;
    items: SaleItem[];
    payments: SalePayment[];
    refunds: PosRefundData[];
    refundMethods: Option[];
    openSession: number | null;
    salesEnabled: boolean;
    canReturn: boolean;
    canReprint: boolean;
    fiscalDocuments?: OriginFiscalDocuments;
}) {
    const { formatDateTime } = useTenant();
    const title = sale.order_number
        ? `Venda #${sale.order_number}`
        : (sale.label ?? `Venda PDV ${sale.id}`);
    const isCompleted = sale.status === 'completed';

    return (
        <>
            <Head title={title} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title={title}
                            description={`${sale.terminal} · sessão ${sale.session_number} · operador ${sale.user_name}${sale.completed_at ? ` · ${formatDateTime(sale.completed_at)}` : ''}`}
                        />
                        <div className="flex flex-wrap gap-1">
                            <Badge
                                variant={
                                    sale.status === 'cancelled'
                                        ? 'destructive'
                                        : 'secondary'
                                }
                            >
                                {sale.status_label}
                            </Badge>
                            {sale.reprints > 0 && (
                                <Badge variant="outline">
                                    {sale.reprints} segunda(s) via(s)
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {salesEnabled && sale.order_id !== null && (
                            <Button variant="outline" asChild>
                                <Link href={orderShow(sale.order_id)}>
                                    Abrir em Vendas
                                </Link>
                            </Button>
                        )}
                        {isCompleted && canReprint && (
                            <>
                                <Button variant="outline" asChild>
                                    <a
                                        href={receipt.url(sale.id, {
                                            query: { copy: 1 },
                                        })}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Segunda via
                                    </a>
                                </Button>
                                <Button variant="outline" asChild>
                                    <a
                                        href={pdf.url(sale.id, {
                                            query: { copy: 1 },
                                        })}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        PDF
                                    </a>
                                </Button>
                            </>
                        )}
                        <ReturnDialog
                            sale={sale}
                            items={items}
                            refundMethods={refundMethods}
                            openSession={openSession}
                            canReturn={canReturn}
                        />
                    </div>
                </div>

                {sale.status === 'cancelled' && (
                    <p className="rounded-lg border border-destructive/40 p-3 text-sm">
                        Cancelada
                        {sale.cancelled_at
                            ? ` em ${formatDateTime(sale.cancelled_at)}`
                            : ''}{' '}
                        por {sale.cancelled_by_name ?? '—'}. Motivo:{' '}
                        {sale.cancellation_reason ?? '—'}
                    </p>
                )}

                <section aria-label="Itens" className="space-y-3">
                    <h3 className="font-medium">
                        Itens · cliente:{' '}
                        {sale.customer_name ?? 'Consumidor não identificado'}
                    </h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Item</th>
                                    <th className="p-3 text-right">
                                        Quantidade
                                    </th>
                                    <th className="p-3 text-right">Unitário</th>
                                    <th className="p-3 text-right">Desconto</th>
                                    <th className="p-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item, position) => (
                                    <tr
                                        key={item.id ?? `cart-${position}`}
                                        className="border-t"
                                    >
                                        <td className="p-3">
                                            {item.description}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatDecimal(item.quantity)}{' '}
                                            {item.unit_code}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(item.unit_price)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(item.discount)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(item.total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                Total
                            </dt>
                            <dd className="text-lg font-semibold">
                                {formatMoney(sale.total)}
                            </dd>
                        </div>
                        <div className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                Recebido (informado)
                            </dt>
                            <dd className="text-lg font-semibold">
                                {formatMoney(sale.received_total)}
                            </dd>
                        </div>
                        <div className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                A prazo
                            </dt>
                            <dd className="text-lg font-semibold">
                                {formatMoney(sale.term_total)}
                            </dd>
                        </div>
                        <div className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                Troco
                            </dt>
                            <dd className="text-lg font-semibold">
                                {formatMoney(sale.change_total)}
                            </dd>
                        </div>
                    </dl>
                    {sale.authorized_by_name && (
                        <p className="text-sm text-muted-foreground">
                            Desconto ou preço autorizado por{' '}
                            {sale.authorized_by_name}.
                        </p>
                    )}
                </section>

                {payments.length > 0 && (
                    <section aria-label="Pagamentos" className="space-y-3">
                        <h3 className="font-medium">Pagamentos</h3>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Forma</th>
                                        <th className="p-3">Situação</th>
                                        <th className="p-3 text-right">
                                            Valor
                                        </th>
                                        <th className="p-3 text-right">
                                            Entregue
                                        </th>
                                        <th className="p-3 text-right">
                                            Troco
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payments.map((payment) => (
                                        <tr
                                            key={payment.sequence}
                                            className="border-t"
                                        >
                                            <td className="p-3">
                                                {payment.method_label}
                                            </td>
                                            <td className="p-3">
                                                {payment.is_received
                                                    ? 'Recebido (informado pelo operador)'
                                                    : `A prazo · vence ${payment.due_date.split('-').reverse().join('/')}`}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {formatMoney(payment.amount)}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {formatMoney(payment.tendered)}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {formatMoney(payment.change)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {refunds.length > 0 && (
                    <section aria-label="Devoluções" className="space-y-3">
                        <h3 className="font-medium">Devoluções</h3>
                        <ul className="space-y-2 text-sm">
                            {refunds.map((refund) => (
                                <li
                                    key={refund.id}
                                    className="rounded-lg border p-3"
                                >
                                    Devolução #{refund.return_number}
                                    {refund.created_at
                                        ? ` em ${formatDateTime(refund.created_at)}`
                                        : ''}
                                    : {formatMoney(refund.return_value)} ·
                                    restituído {formatMoney(refund.amount)} em{' '}
                                    {refund.method_label}
                                    <span className="block text-xs text-muted-foreground">
                                        {refund.user_name}
                                        {refund.authorized_by_name &&
                                        refund.authorized_by_name !==
                                            refund.user_name
                                            ? ` · autorizado por ${refund.authorized_by_name}`
                                            : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <OriginFiscalDocumentsSection
                    data={fiscalDocuments}
                    canCreate={sale.status === 'completed'}
                />

                <p className="text-xs text-muted-foreground">
                    Comprovante comercial não fiscal. Sem validade fiscal.
                </p>
            </div>
        </>
    );
}

PosSaleShow.layout = {
    breadcrumbs: [
        posBreadcrumb,
        { title: 'Vendas do caixa', href: index() },
        { title: 'Venda', href: '#' },
    ],
};
