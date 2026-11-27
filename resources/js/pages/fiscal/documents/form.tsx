import { Head, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { fiscalBreadcrumb } from '@/components/fiscal/fiscal-nav';
import { FiscalEnvironmentBadge } from '@/components/fiscal/fiscal-status';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney, parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { index, store, update } from '@/routes/fiscal/documents';
import type {
    FiscalDocumentDetail,
    FiscalItem,
    FiscalReceiver,
    FiscalTaxData,
    Option,
} from '@/types';

type Prefill = {
    origin: string;
    origin_id: number;
    origin_label: string;
    receiver_party_id: number | null;
    receiver: FiscalReceiver | null;
    items: FiscalItem[];
    description: string | null;
    payment_method: string | null;
    suggested_type: string;
    notice: string | null;
};

type Party = { id: number; name: string; document: string | null };

type FormData = {
    type: string;
    receiver_party_id: string;
    receiver: FiscalReceiver;
    items: FiscalItem[];
    description: string;
    notes: string;
    payment_method: string;
    tax_data: FiscalTaxData;
};

const EMPTY_ITEM: FiscalItem = {
    description: '',
    quantity: '1',
    unit_price: '',
    discount: '',
};

/** Campos fiscais do item: somente o formato é conferido; nenhum código é sugerido. */
const ITEM_FISCAL_FIELDS: [keyof FiscalItem, string, string][] = [
    ['ncm', 'NCM', '8 dígitos'],
    ['cest', 'CEST', '7 dígitos, se houver'],
    ['cfop', 'CFOP', 'Saída: 5xxx (interestadual ajustado na emissão)'],
    ['origin', 'Origem', '0 a 8'],
    ['icms_situation', 'CST/CSOSN do ICMS', 'Ex.: 102 (Simples) ou 41'],
    ['pis_situation', 'CST do PIS', '2 dígitos'],
    ['cofins_situation', 'CST do COFINS', '2 dígitos'],
    ['gtin', 'GTIN/EAN', 'Se houver'],
    ['unit', 'Unidade', 'Ex.: UN, KG'],
];

export default function FiscalDocumentForm({
    document,
    prefill,
    types,
    paymentMethods,
    parties,
    environment,
}: {
    document: FiscalDocumentDetail | null;
    prefill: Prefill | null;
    types: Option[];
    paymentMethods: Option[];
    parties: Party[];
    hasProducts: boolean;
    environment: string;
}) {
    const source = document ?? prefill;
    const idempotencyKey = useMemo(() => uuid(), []);
    const form = useForm<FormData>({
        type: document?.type ?? prefill?.suggested_type ?? 'nfse',
        receiver_party_id: source?.receiver_party_id
            ? String(source.receiver_party_id)
            : '',
        receiver: source?.receiver ?? { address: {} },
        items: source?.items?.length ? source.items : [{ ...EMPTY_ITEM }],
        description: source?.description ?? '',
        notes: document?.notes ?? '',
        payment_method: source?.payment_method ?? '',
        tax_data: document?.tax_data ?? {},
    });
    const errors = form.errors as Record<string, string | undefined>;
    const isService = form.data.type === 'nfse';
    const receiver = form.data.receiver;
    const address = receiver.address ?? {};

    const setItem = (
        position: number,
        field: keyof FiscalItem,
        value: string,
    ) =>
        form.setData(
            'items',
            form.data.items.map((item, current) =>
                current === position ? { ...item, [field]: value } : item,
            ),
        );
    const setReceiver = (field: keyof FiscalReceiver, value: string) =>
        form.setData('receiver', { ...receiver, [field]: value });
    const setAddress = (field: string, value: string) =>
        form.setData('receiver', {
            ...receiver,
            address: { ...address, [field]: value },
        });
    const total = form.data.items.reduce((sum, item) => {
        const quantity = Number(parseDecimal(item.quantity || '0')) || 0;
        const price = Number(parseDecimal(item.unit_price || '0')) || 0;
        const discount = Number(parseDecimal(item.discount ?? '0')) || 0;

        return sum + quantity * price - discount;
    }, 0);

    return (
        <>
            <Head
                title={
                    document
                        ? 'Editar documento fiscal'
                        : 'Novo documento fiscal'
                }
            />
            <form
                className="space-y-6 p-4 md:p-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        receiver_party_id: data.receiver_party_id || null,
                        payment_method: data.payment_method || null,
                        items: data.items.map((item) => ({
                            ...item,
                            quantity: parseDecimal(item.quantity || '0'),
                            unit_price: parseDecimal(item.unit_price || '0'),
                            discount: item.discount
                                ? parseDecimal(item.discount)
                                : null,
                            is_service: isService,
                        })),
                        tax_data: {
                            ...data.tax_data,
                            iss_rate: data.tax_data.iss_rate
                                ? parseDecimal(data.tax_data.iss_rate)
                                : null,
                        },
                        ...(document
                            ? {}
                            : {
                                  origin: prefill?.origin ?? 'manual',
                                  origin_id: prefill?.origin_id ?? null,
                                  idempotency_key: idempotencyKey,
                              }),
                    }));

                    if (document) {
                        form.put(update.url(document.id), {
                            preserveScroll: true,
                        });
                    } else {
                        form.post(store.url(), { preserveScroll: true });
                    }
                }}
            >
                <div className="space-y-1">
                    <Heading
                        variant="small"
                        title={
                            document
                                ? `Editar documento interno ${document.local_number}`
                                : 'Novo documento fiscal'
                        }
                        description="Salvar guarda um rascunho. A emissão é solicitada depois, na ficha do documento, após a validação."
                    />
                    <FiscalEnvironmentBadge environment={environment} />
                    {prefill && (
                        <p className="text-sm text-muted-foreground">
                            Origem: {prefill.origin_label}. O documento fiscal
                            não altera a origem (sem recebimento, estoque ou
                            caixa).
                        </p>
                    )}
                    {prefill?.notice && (
                        <p className="rounded-lg border border-amber-500/50 p-3 text-sm">
                            {prefill.notice}
                        </p>
                    )}
                    {errors.origin_id && (
                        <p className="text-sm text-destructive">
                            {errors.origin_id}
                        </p>
                    )}
                </div>

                <section className="grid gap-4 md:grid-cols-3">
                    <FormField
                        id="type"
                        label="Tipo de documento"
                        error={errors.type}
                    >
                        <NativeSelect
                            id="type"
                            value={form.data.type}
                            disabled={document !== null}
                            onChange={(event) =>
                                form.setData('type', event.target.value)
                            }
                        >
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="payment_method"
                        label="Forma de pagamento informada"
                        error={errors.payment_method}
                    >
                        <NativeSelect
                            id="payment_method"
                            value={form.data.payment_method}
                            onChange={(event) =>
                                form.setData(
                                    'payment_method',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Não informada</option>
                            {paymentMethods.map((method) => (
                                <option key={method.value} value={method.value}>
                                    {method.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                </section>

                <section aria-label="Destinatário" className="space-y-3">
                    <h3 className="font-medium">
                        {isService ? 'Tomador do serviço' : 'Destinatário'}
                    </h3>
                    <FormField
                        id="receiver_party_id"
                        label="Pessoa cadastrada"
                        hint="Ao escolher, os dados do cadastro são copiados para o documento."
                        error={errors.receiver_party_id}
                    >
                        <NativeSelect
                            id="receiver_party_id"
                            value={form.data.receiver_party_id}
                            onChange={(event) =>
                                form.setData(
                                    'receiver_party_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">
                                {form.data.type === 'nfce'
                                    ? 'Consumidor não identificado'
                                    : 'Informar abaixo'}
                            </option>
                            {parties.map((party) => (
                                <option key={party.id} value={party.id}>
                                    {party.name}
                                    {party.document
                                        ? ` — ${party.document}`
                                        : ''}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <div className="grid gap-4 md:grid-cols-3">
                        {(
                            [
                                ['name', 'Nome ou razão social'],
                                ['document', 'CPF ou CNPJ'],
                                ['state_registration', 'Inscrição estadual'],
                                [
                                    'municipal_registration',
                                    'Inscrição municipal',
                                ],
                                ['email', 'E-mail'],
                                ['phone', 'Telefone'],
                            ] as [
                                Exclude<keyof FiscalReceiver, 'address'>,
                                string,
                            ][]
                        ).map(([field, label]) => (
                            <FormField
                                key={field}
                                id={`receiver-${field}`}
                                label={label}
                                error={errors[`receiver.${field}`]}
                            >
                                <Input
                                    id={`receiver-${field}`}
                                    value={receiver[field] ?? ''}
                                    onChange={(event) =>
                                        setReceiver(field, event.target.value)
                                    }
                                />
                            </FormField>
                        ))}
                        {[
                            ['postal_code', 'CEP'],
                            ['street', 'Logradouro'],
                            ['number', 'Número'],
                            ['complement', 'Complemento'],
                            ['district', 'Bairro'],
                            ['city', 'Cidade'],
                            ['city_code', 'Código IBGE do município'],
                            ['state', 'UF'],
                        ].map(([field, label]) => (
                            <FormField
                                key={field}
                                id={`address-${field}`}
                                label={label}
                                error={errors[`receiver.address.${field}`]}
                            >
                                <Input
                                    id={`address-${field}`}
                                    value={String(
                                        (
                                            address as Record<
                                                string,
                                                string | null
                                            >
                                        )[field] ?? '',
                                    )}
                                    onChange={(event) =>
                                        setAddress(field, event.target.value)
                                    }
                                />
                            </FormField>
                        ))}
                    </div>
                </section>

                <section aria-label="Itens" className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-medium">
                            {isService ? 'Serviços' : 'Mercadorias'}
                        </h3>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('items', [
                                    ...form.data.items,
                                    { ...EMPTY_ITEM },
                                ])
                            }
                        >
                            Adicionar item
                        </Button>
                    </div>
                    {errors.items && (
                        <p className="text-sm text-destructive">
                            {errors.items}
                        </p>
                    )}
                    {form.data.items.map((item, position) => (
                        <fieldset
                            key={position}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <legend className="px-1 text-sm font-medium">
                                Item {position + 1}
                            </legend>
                            <div className="grid gap-3 md:grid-cols-[2fr_repeat(3,1fr)_auto]">
                                <FormField
                                    id={`item-${position}-description`}
                                    label="Descrição"
                                    error={
                                        errors[`items.${position}.description`]
                                    }
                                >
                                    <Input
                                        id={`item-${position}-description`}
                                        required
                                        value={item.description}
                                        onChange={(event) =>
                                            setItem(
                                                position,
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                                {(
                                    [
                                        ['quantity', 'Quantidade'],
                                        ['unit_price', 'Valor unitário'],
                                        ['discount', 'Desconto'],
                                    ] as [keyof FiscalItem, string][]
                                ).map(([field, label]) => (
                                    <FormField
                                        key={field}
                                        id={`item-${position}-${field}`}
                                        label={label}
                                        error={
                                            errors[`items.${position}.${field}`]
                                        }
                                    >
                                        <Input
                                            id={`item-${position}-${field}`}
                                            inputMode="decimal"
                                            value={String(item[field] ?? '')}
                                            onChange={(event) =>
                                                setItem(
                                                    position,
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </FormField>
                                ))}
                                <div className="flex items-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={form.data.items.length === 1}
                                        onClick={() =>
                                            form.setData(
                                                'items',
                                                form.data.items.filter(
                                                    (_, current) =>
                                                        current !== position,
                                                ),
                                            )
                                        }
                                    >
                                        Remover
                                    </Button>
                                </div>
                            </div>
                            {isService ? (
                                <FormField
                                    id={`item-${position}-service_code`}
                                    label="Item da lista de serviços (LC 116)"
                                    hint="Em branco: usa o padrão das configurações fiscais."
                                    error={
                                        errors[`items.${position}.service_code`]
                                    }
                                    className="max-w-xs"
                                >
                                    <Input
                                        id={`item-${position}-service_code`}
                                        value={String(item.service_code ?? '')}
                                        onChange={(event) =>
                                            setItem(
                                                position,
                                                'service_code',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                    {ITEM_FISCAL_FIELDS.map(
                                        ([field, label, hint]) => (
                                            <FormField
                                                key={field}
                                                id={`item-${position}-${field}`}
                                                label={label}
                                                hint={hint}
                                                error={
                                                    errors[
                                                        `items.${position}.${field}`
                                                    ]
                                                }
                                            >
                                                <Input
                                                    id={`item-${position}-${field}`}
                                                    value={String(
                                                        item[field] ?? '',
                                                    )}
                                                    onChange={(event) =>
                                                        setItem(
                                                            position,
                                                            field,
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </FormField>
                                        ),
                                    )}
                                </div>
                            )}
                        </fieldset>
                    ))}
                    <p className="text-right text-sm">
                        Total estimado:{' '}
                        <strong>{formatMoney(total.toFixed(2))}</strong>
                    </p>
                </section>

                {isService && (
                    <section
                        aria-label="Tributação do serviço"
                        className="space-y-3"
                    >
                        <h3 className="font-medium">Tributação do serviço</h3>
                        <p className="text-sm text-muted-foreground">
                            Em branco, valem os padrões confirmados nas
                            configurações fiscais.
                        </p>
                        <div className="grid gap-4 md:grid-cols-3">
                            {(
                                [
                                    [
                                        'service_code',
                                        'Item da lista de serviços (LC 116)',
                                    ],
                                    ['iss_rate', 'Alíquota de ISS (%)'],
                                    [
                                        'city_code',
                                        'Município da prestação (IBGE)',
                                    ],
                                ] as [keyof FiscalTaxData, string][]
                            ).map(([field, label]) => (
                                <FormField
                                    key={field}
                                    id={`tax-${field}`}
                                    label={label}
                                    error={errors[`tax_data.${field}`]}
                                >
                                    <Input
                                        id={`tax-${field}`}
                                        value={String(
                                            form.data.tax_data[field] ?? '',
                                        )}
                                        onChange={(event) =>
                                            form.setData('tax_data', {
                                                ...form.data.tax_data,
                                                [field]: event.target.value,
                                            })
                                        }
                                    />
                                </FormField>
                            ))}
                        </div>
                        <FormField
                            id="description"
                            label="Discriminação do serviço"
                            error={errors.description}
                        >
                            <NativeTextarea
                                id="description"
                                maxLength={2000}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                    </section>
                )}

                <FormField
                    id="notes"
                    label="Informações complementares"
                    error={errors.notes}
                >
                    <NativeTextarea
                        id="notes"
                        maxLength={2000}
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                    />
                </FormField>

                <div className="flex flex-wrap gap-2">
                    <Button disabled={form.processing}>Salvar rascunho</Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => window.history.back()}
                    >
                        Voltar
                    </Button>
                </div>
            </form>
        </>
    );
}

FiscalDocumentForm.layout = {
    breadcrumbs: [
        fiscalBreadcrumb,
        { title: 'Documentos', href: index() },
        { title: 'Documento', href: '#' },
    ],
};
