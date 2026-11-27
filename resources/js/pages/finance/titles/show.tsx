import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import MoneyInput from '@/components/finance/money-input';
import SettleDialog from '@/components/finance/settle-dialog';
import TitleStatus from '@/components/finance/title-status';
import OriginFiscalDocumentsSection, {
    type OriginFiscalDocuments,
} from '@/components/fiscal/origin-fiscal-documents';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import OriginChargesSection, {
    type OriginCharges,
} from '@/components/payments/origin-charges';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import ReasonDialog from '@/components/reason-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { monthlySchedule } from '@/lib/money';
import {
    show as attachmentUrl,
    store as storeAttachment,
} from '@/routes/finance/attachments';
import { reverse } from '@/routes/finance/settlements';
import {
    cancel,
    index,
    renegotiate,
    show,
    update,
} from '@/routes/finance/titles';
import type {
    FinanceCategory,
    FinanceOption,
    FinanceTitle,
    FinanceTitleSummary,
    Option,
} from '@/types';

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

function EditDialog({
    title,
    categories,
}: {
    title: FinanceTitle;
    categories: FinanceCategory[];
}) {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({
        description: title.description,
        amount: toDecimalInput(title.original_amount, 2),
        competence_date: title.competence_date,
        due_date: title.due_date,
        finance_category_id:
            title.finance_category_id === null
                ? ''
                : String(title.finance_category_id),
        notes: title.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const lockValues = title.has_settlements;

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">Editar</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            amount:
                                lockValues || title.is_integration
                                    ? undefined
                                    : parseDecimal(data.amount),
                            due_date: lockValues ? undefined : data.due_date,
                            finance_category_id:
                                data.finance_category_id || null,
                        }));
                        form.put(update.url(title.id), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Editar título #{title.number}</DialogTitle>
                    <DialogDescription>
                        {lockValues
                            ? 'O título já tem baixas: valor e vencimento só mudam por renegociação.'
                            : title.is_integration
                              ? 'O valor deste título é ajustado pelo documento de origem.'
                              : 'Alterações ficam registradas no histórico.'}
                    </DialogDescription>
                    <FormField
                        id="edit-description"
                        label="Descrição"
                        error={errors.description}
                    >
                        <Input
                            id="edit-description"
                            required
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="edit-amount"
                            label="Valor"
                            error={errors.amount}
                        >
                            <MoneyInput
                                id="edit-amount"
                                disabled={lockValues || title.is_integration}
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="edit-due"
                            label="Vencimento"
                            error={errors.due_date}
                        >
                            <Input
                                id="edit-due"
                                type="date"
                                disabled={lockValues}
                                value={form.data.due_date}
                                onChange={(event) =>
                                    form.setData('due_date', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="edit-competence"
                            label="Competência"
                            error={errors.competence_date}
                        >
                            <Input
                                id="edit-competence"
                                type="date"
                                required
                                value={form.data.competence_date}
                                onChange={(event) =>
                                    form.setData(
                                        'competence_date',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="edit-category"
                            label="Categoria"
                            error={errors.finance_category_id}
                        >
                            <NativeSelect
                                id="edit-category"
                                value={form.data.finance_category_id}
                                onChange={(event) =>
                                    form.setData(
                                        'finance_category_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Sem categoria</option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.full_name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
                    <FormField
                        id="edit-notes"
                        label="Observações"
                        error={errors.notes}
                    >
                        <NativeTextarea
                            id="edit-notes"
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter>
                        <Button disabled={form.processing}>Salvar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RenegotiateDialog({
    title,
    today,
}: {
    title: FinanceTitle;
    today: string;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({
        titles: [title.id],
        installments: '1',
        first_due: today,
        total: toDecimalInput(title.open, 2),
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">Renegociar</Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            titles: data.titles,
                            reason: data.reason,
                            schedule: monthlySchedule(
                                data.total,
                                Math.max(1, Number(data.installments) || 1),
                                data.first_due,
                            ),
                        }));
                        form.post(renegotiate.url(), {
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Renegociar título #{title.number}</DialogTitle>
                    <DialogDescription>
                        O saldo em aberto ({formatMoney(title.open)}) é
                        substituído por novas parcelas. O título original fica
                        no histórico como renegociado.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <FormField
                            id="reneg-total"
                            label="Novo total"
                            error={errors.schedule}
                        >
                            <MoneyInput
                                id="reneg-total"
                                value={form.data.total}
                                onChange={(event) =>
                                    form.setData('total', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField id="reneg-count" label="Parcelas">
                            <Input
                                id="reneg-count"
                                type="number"
                                min={1}
                                max={120}
                                value={form.data.installments}
                                onChange={(event) =>
                                    form.setData(
                                        'installments',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField id="reneg-due" label="1º vencimento">
                            <Input
                                id="reneg-due"
                                type="date"
                                value={form.data.first_due}
                                onChange={(event) =>
                                    form.setData(
                                        'first_due',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    <FormField
                        id="reneg-reason"
                        label="Motivo"
                        error={errors.reason ?? errors.titles}
                    >
                        <NativeTextarea
                            id="reneg-reason"
                            required
                            maxLength={400}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter>
                        <Button disabled={form.processing}>Renegociar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AttachmentUpload({
    title,
    rules,
}: {
    title: FinanceTitle;
    rules: { mimes: string[]; maximum_kilobytes: number };
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storeAttachment.url(title.id), {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Input
                type="file"
                aria-label="Arquivo"
                className="w-full sm:w-auto"
                accept={rules.mimes.map((mime) => `.${mime}`).join(',')}
                onChange={(event) =>
                    form.setData('file', event.target.files?.[0] ?? null)
                }
            />
            <Button
                variant="outline"
                disabled={form.processing || form.data.file === null}
            >
                Enviar anexo
            </Button>
            <p className="w-full text-xs text-muted-foreground">
                {rules.mimes.join(', ').toUpperCase()} · até{' '}
                {Math.round(rules.maximum_kilobytes / 1024)} MB
            </p>
            {form.errors.file && (
                <p className="w-full text-sm text-destructive">
                    {form.errors.file}
                </p>
            )}
        </form>
    );
}

export default function FinanceTitleShow({
    title,
    related,
    categories,
    accounts,
    paymentMethods,
    paymentCharges = null,
    fiscalDocuments = null,
    today,
    attachmentRules,
}: {
    title: FinanceTitle;
    related: FinanceTitleSummary[];
    categories: FinanceCategory[];
    accounts: FinanceOption[];
    paymentMethods: Option[];
    paymentCharges?: OriginCharges;
    fiscalDocuments?: OriginFiscalDocuments;
    today: string;
    attachmentRules: { mimes: string[]; maximum_kilobytes: number };
}) {
    const { can, formatDateTime } = useTenant();
    const isReceivable = title.kind === 'receivable';
    const canManage = can(
        isReceivable ? 'finance.manage_receivables' : 'finance.manage_payables',
    );
    const isOpen = title.status === 'pending' || title.status === 'partial';

    return (
        <>
            <Head title={`Título #${title.number}`} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Link
                            className="text-sm text-muted-foreground hover:underline"
                            href={index(
                                isReceivable ? 'receivables' : 'payables',
                            )}
                        >
                            ←{' '}
                            {isReceivable
                                ? 'Contas a receber'
                                : 'Contas a pagar'}
                        </Link>
                        <Heading
                            variant="small"
                            title={`#${title.number} — ${title.description}`}
                            description={`${title.origin_label}${title.source_label ? `: ${title.source_label}` : ''}`}
                        />
                        <TitleStatus title={title} />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {isOpen &&
                            can(
                                isReceivable
                                    ? 'finance.receive'
                                    : 'finance.pay',
                            ) && (
                                <SettleDialog
                                    title={title}
                                    accounts={accounts}
                                    paymentMethods={paymentMethods}
                                    today={today}
                                />
                            )}
                        {isOpen && canManage && (
                            <EditDialog title={title} categories={categories} />
                        )}
                        {isOpen &&
                            canManage &&
                            can('finance.cancel_titles') && (
                                <RenegotiateDialog
                                    title={title}
                                    today={today}
                                />
                            )}
                        {isOpen && can('finance.cancel_titles') && (
                            <ReasonDialog
                                url={cancel.url(title.id)}
                                title="Cancelar saldo em aberto"
                                description="Valores já liquidados permanecem. Só o saldo em aberto é cancelado."
                                confirmLabel="Cancelar saldo"
                            >
                                Cancelar
                            </ReasonDialog>
                        )}
                    </div>
                </div>
                <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-3 lg:grid-cols-4">
                    <Fact label="Pessoa" value={title.party_name ?? '—'} />
                    <Fact label="Categoria" value={title.category ?? '—'} />
                    <Fact
                        label="Competência"
                        value={formatDate(title.competence_date)}
                    />
                    <Fact
                        label="Vencimento"
                        value={formatDate(title.due_date)}
                    />
                    <Fact
                        label="Valor original"
                        value={formatMoney(title.original_amount)}
                    />
                    <Fact
                        label="Ajustes de origem"
                        value={formatMoney(title.adjusted_amount)}
                    />
                    <Fact
                        label="Cancelado"
                        value={formatMoney(title.cancelled_amount)}
                    />
                    <Fact
                        label="Principal liquidado"
                        value={formatMoney(title.settled)}
                    />
                    <Fact
                        label="Juros"
                        value={formatMoney(title.interest_total)}
                    />
                    <Fact
                        label="Multas"
                        value={formatMoney(title.fine_total)}
                    />
                    <Fact
                        label="Descontos"
                        value={formatMoney(title.discount_total)}
                    />
                    <Fact
                        label="Saldo em aberto"
                        value={formatMoney(title.open)}
                    />
                    {title.installment && (
                        <Fact label="Parcela" value={title.installment} />
                    )}
                    <Fact
                        label="Lançado por"
                        value={title.user_name ?? 'Sistema'}
                    />
                </dl>
                {title.notes && (
                    <p className="rounded-lg border p-4 text-sm whitespace-pre-line">
                        {title.notes}
                    </p>
                )}
                {title.cancellation_reason && (
                    <p className="rounded-lg border border-dashed p-4 text-sm">
                        Motivo do cancelamento: {title.cancellation_reason}
                    </p>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">Baixas</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Data</th>
                                    <th className="p-3">Conta e forma</th>
                                    <th className="p-3 text-right">
                                        Principal
                                    </th>
                                    <th className="p-3 text-right">
                                        Juros/multa
                                    </th>
                                    <th className="p-3 text-right">Desconto</th>
                                    <th className="p-3 text-right">
                                        Movimentado
                                    </th>
                                    <th className="p-3">Situação</th>
                                    <th className="p-3">
                                        <span className="sr-only">Ações</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {title.settlements.length === 0 && (
                                    <tr>
                                        <td
                                            className="p-6 text-center text-muted-foreground"
                                            colSpan={8}
                                        >
                                            Nenhuma baixa registrada.
                                        </td>
                                    </tr>
                                )}
                                {title.settlements.map((settlement) => (
                                    <tr
                                        key={settlement.id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3 whitespace-nowrap">
                                            {formatDate(settlement.settled_at)}
                                        </td>
                                        <td className="p-3">
                                            {settlement.is_external
                                                ? 'Liquidação externa (sem conta)'
                                                : settlement.account}
                                            <span className="block text-xs text-muted-foreground">
                                                {[
                                                    settlement.method,
                                                    settlement.user_name,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                            {settlement.notes && (
                                                <span className="block text-xs">
                                                    {settlement.notes}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(settlement.principal)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(settlement.interest)} /{' '}
                                            {formatMoney(settlement.fine)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(settlement.discount)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatMoney(settlement.amount)}
                                        </td>
                                        <td className="p-3">
                                            {settlement.status_label}
                                            {settlement.reversal_reason && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {
                                                        settlement.reversed_by_name
                                                    }
                                                    :{' '}
                                                    {settlement.reversal_reason}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right">
                                            {settlement.status ===
                                                'confirmed' &&
                                                can(
                                                    'finance.reverse_settlements',
                                                ) && (
                                                    <ReasonDialog
                                                        size="sm"
                                                        url={reverse.url(
                                                            settlement.id,
                                                        )}
                                                        title="Estornar baixa"
                                                        description="Será gerada uma movimentação compensatória. A baixa original permanece no histórico."
                                                        confirmLabel="Estornar"
                                                    >
                                                        Estornar
                                                    </ReasonDialog>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {related.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">Outras parcelas</h2>
                        <ul className="divide-y rounded-lg border text-sm">
                            {related.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                                >
                                    <Link
                                        className="underline-offset-4 hover:underline"
                                        href={show(item.id)}
                                    >
                                        #{item.number} · {item.installment} ·{' '}
                                        {formatDate(item.due_date)}
                                    </Link>
                                    <span className="flex items-center gap-2">
                                        {formatMoney(item.open)}{' '}
                                        <TitleStatus title={item} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">Anexos</h2>
                    {title.attachments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nenhum anexo. Anexe comprovantes, notas ou boletos
                            recebidos.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {title.attachments.map((attachment) => (
                                <li
                                    key={attachment.id}
                                    className="flex flex-wrap justify-between gap-2 p-3"
                                >
                                    <a
                                        className="underline-offset-4 hover:underline"
                                        href={attachmentUrl.url(attachment.id)}
                                    >
                                        {attachment.original_name}
                                    </a>
                                    <span className="text-xs text-muted-foreground">
                                        {Math.ceil(attachment.size / 1024)} KB ·{' '}
                                        {attachment.user_name}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {canManage && (
                        <AttachmentUpload
                            title={title}
                            rules={attachmentRules}
                        />
                    )}
                </section>

                <section className="space-y-2">
                    <h2 className="font-medium">Histórico</h2>
                    <ol className="space-y-2 text-sm">
                        {title.events.map((event) => (
                            <li
                                key={event.id}
                                className="rounded-md border p-3"
                            >
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(event.occurred_at)} ·{' '}
                                    {event.user_name ?? 'Sistema'}
                                </span>
                                <span className="block">
                                    {event.description}
                                    {event.amount !== null &&
                                        ` (${formatMoney(event.amount)})`}
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>
                <OriginChargesSection data={paymentCharges} isOpen={isOpen} />
                <OriginFiscalDocumentsSection data={fiscalDocuments} />
            </div>
        </>
    );
}

FinanceTitleShow.layout = { breadcrumbs: [financeBreadcrumb] };
