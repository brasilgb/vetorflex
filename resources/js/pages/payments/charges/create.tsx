import { Head, Link, useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { EnvironmentBadge } from '@/components/payments/charge-status';
import { paymentsBreadcrumb } from '@/components/payments/payments-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { settings } from '@/routes/payments';
import { index, store } from '@/routes/payments/charges';

type Prefill = {
    origin: 'sale' | 'finance';
    origin_id: number;
    label: string | null;
    amount: string | null;
    description: string | null;
    due_date: string | null;
    payer_name: string | null;
    has_payer: boolean;
} | null;

export default function CreatePaymentCharge({
    prefill,
    account,
    today,
}: {
    prefill: Prefill;
    account: { provider: string; environment: string; name: string } | null;
    today: string;
}) {
    const form = useForm({
        description: prefill?.description ?? '',
        amount: toDecimalInput(prefill?.amount ?? null, 2),
        due_date: prefill?.due_date ?? today,
        reference: '',
        identify: !prefill?.has_payer,
        payer: {
            name: prefill?.payer_name ?? '',
            document: '',
            email: '',
            phone: '',
        },
        issue: true,
        idempotency_key: uuid(),
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title="Nova cobrança" />
            <div className="max-w-2xl space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Nova cobrança"
                    description={
                        prefill?.label
                            ? `Cobrança de ${prefill.label}. Saldo devido: ${formatMoney(prefill.amount)}.`
                            : 'Cobrança Pix avulsa: o pagador recebe o QR Code ou o código copia e cola.'
                    }
                />
                {account === null ? (
                    <p className="rounded-lg border border-dashed p-3 text-sm">
                        Nenhum provedor conectado: a cobrança será salva como
                        rascunho. Para emitir,{' '}
                        <Link
                            href={settings()}
                            className="underline underline-offset-4"
                        >
                            configure a conta da empresa no provedor
                        </Link>
                        .
                    </p>
                ) : (
                    <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        Será emitida em {account.name}.{' '}
                        <EnvironmentBadge environment={account.environment} />
                    </p>
                )}
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            description: data.description || null,
                            amount:
                                data.amount.trim() === ''
                                    ? null
                                    : parseDecimal(data.amount),
                            due_date: data.due_date,
                            reference: data.reference || null,
                            origin: prefill?.origin ?? 'manual',
                            origin_id: prefill?.origin_id ?? null,
                            payer:
                                data.identify && data.payer.name.trim() !== ''
                                    ? data.payer
                                    : null,
                            issue: data.issue,
                            idempotency_key: data.idempotency_key,
                        }));
                        form.post(store.url());
                    }}
                >
                    <FormField
                        id="charge-description"
                        label="Descrição"
                        error={form.errors.description}
                    >
                        <Input
                            id="charge-description"
                            required={prefill === null}
                            maxLength={200}
                            placeholder="Ex.: Mensalidade de novembro"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <FormField
                            id="charge-amount"
                            label="Valor"
                            error={form.errors.amount}
                        >
                            <MoneyInput
                                id="charge-amount"
                                required={prefill === null}
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="charge-due"
                            label="Vencimento"
                            error={form.errors.due_date}
                        >
                            <Input
                                id="charge-due"
                                type="date"
                                required
                                value={form.data.due_date}
                                onChange={(event) =>
                                    form.setData('due_date', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="charge-reference"
                            label="Referência interna"
                            error={form.errors.reference}
                        >
                            <Input
                                id="charge-reference"
                                maxLength={60}
                                value={form.data.reference}
                                onChange={(event) =>
                                    form.setData(
                                        'reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </div>
                    {prefill?.has_payer ? (
                        <p className="text-sm text-muted-foreground">
                            Pagador: {prefill.payer_name} (cadastro da origem).
                        </p>
                    ) : (
                        <fieldset className="space-y-3 rounded-lg border p-3">
                            <CheckboxField
                                id="charge-identify"
                                label="Identificar o pagador"
                                checked={form.data.identify}
                                onChange={(checked) =>
                                    form.setData('identify', checked)
                                }
                            />
                            {form.data.identify && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField
                                        id="payer-name"
                                        label="Nome"
                                        error={errors['payer.name']}
                                    >
                                        <Input
                                            id="payer-name"
                                            maxLength={160}
                                            value={form.data.payer.name}
                                            onChange={(event) =>
                                                form.setData('payer', {
                                                    ...form.data.payer,
                                                    name: event.target.value,
                                                })
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="payer-document"
                                        label="CPF ou CNPJ (opcional)"
                                        error={errors['payer.document']}
                                    >
                                        <Input
                                            id="payer-document"
                                            inputMode="numeric"
                                            maxLength={20}
                                            value={form.data.payer.document}
                                            onChange={(event) =>
                                                form.setData('payer', {
                                                    ...form.data.payer,
                                                    document:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="payer-email"
                                        label="E-mail (opcional)"
                                        error={errors['payer.email']}
                                    >
                                        <Input
                                            id="payer-email"
                                            type="email"
                                            maxLength={160}
                                            value={form.data.payer.email}
                                            onChange={(event) =>
                                                form.setData('payer', {
                                                    ...form.data.payer,
                                                    email: event.target.value,
                                                })
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="payer-phone"
                                        label="Telefone (opcional)"
                                        error={errors['payer.phone']}
                                    >
                                        <Input
                                            id="payer-phone"
                                            maxLength={30}
                                            value={form.data.payer.phone}
                                            onChange={(event) =>
                                                form.setData('payer', {
                                                    ...form.data.payer,
                                                    phone: event.target.value,
                                                })
                                            }
                                        />
                                    </FormField>
                                </div>
                            )}
                            <p className="text-xs text-muted-foreground">
                                O pagador é cadastrado uma única vez na base de
                                pessoas da empresa. Com CPF ou CNPJ, ele aparece
                                no Pix.
                            </p>
                        </fieldset>
                    )}
                    {account !== null && (
                        <CheckboxField
                            id="charge-issue"
                            label="Emitir agora no provedor"
                            checked={form.data.issue}
                            onChange={(checked) =>
                                form.setData('issue', checked)
                            }
                        />
                    )}
                    {(errors.origin ||
                        errors.origin_id ||
                        errors.idempotency_key ||
                        errors.provider) && (
                        <p className="text-sm text-destructive">
                            {errors.origin ??
                                errors.origin_id ??
                                errors.idempotency_key ??
                                errors.provider}
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Button disabled={form.processing}>
                            {account !== null && form.data.issue
                                ? 'Criar e emitir'
                                : 'Salvar rascunho'}
                        </Button>
                        <Button variant="secondary" asChild>
                            <Link href={index()}>Cancelar</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

CreatePaymentCharge.layout = {
    breadcrumbs: [
        paymentsBreadcrumb,
        { title: 'Cobranças', href: index() },
        { title: 'Nova cobrança', href: '#' },
    ],
};
