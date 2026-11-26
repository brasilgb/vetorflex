import type { InertiaFormProps } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import RemoteLookup from '@/components/remote-lookup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney, parseDecimal, toDecimalInput } from '@/lib/decimal';
import { lookup as itemLookup } from '@/routes/purchases/items';
import type { Option, PurchasableItem, PurchaseOrder } from '@/types';

export type OrderItemRow = {
    id: number | null;
    product_variant_id: number;
    description: string;
    unit_code: string;
    allows_fraction: boolean;
    supplier_code: string | null;
    quantity: string;
    unit_cost: string;
    discount: string;
    is_free: boolean;
};

export type InstallmentRow = {
    due_date: string;
    amount: string;
    method: string;
};

export type OrderFormData = {
    supplier_profile_id: number | null;
    order_date: string;
    expected_date: string;
    external_reference: string;
    notes: string;
    discount: string;
    freight: string;
    insurance: string;
    other_costs: string;
    payment_condition: string;
    payment_method: string;
    payment_notes: string;
    installments: InstallmentRow[];
    items: OrderItemRow[];
    idempotency_key: string;
};

/** Valores em centavos (ou décimos de milésimo) como inteiros, sem ponto flutuante. */
function scaled(value: string, scale: number): bigint {
    const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(parseDecimal(value || '0'));

    if (match === null) {
        return 0n;
    }

    const digits =
        (match[2] || '0') + (match[3] ?? '').padEnd(scale, '0').slice(0, scale);

    return BigInt(digits) * (match[1] === '-' ? -1n : 1n);
}

function centsToString(cents: bigint): string {
    const sign = cents < 0n ? '-' : '';
    const digits = (cents < 0n ? -cents : cents).toString().padStart(3, '0');

    return `${sign}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

export function itemCents(row: OrderItemRow): bigint {
    if (row.is_free) {
        return 0n;
    }

    // quantidade (4 casas) × custo (4 casas) = 8 casas; arredonda a centavos.
    const product = scaled(row.quantity, 4) * scaled(row.unit_cost, 4);
    const gross = (product + 500000n) / 1000000n;

    return gross - scaled(row.discount, 2);
}

export function orderTotals(data: OrderFormData): {
    items: string;
    total: string;
} {
    const items = data.items.reduce((sum, row) => sum + itemCents(row), 0n);
    const total =
        items -
        scaled(data.discount, 2) +
        scaled(data.freight, 2) +
        scaled(data.insurance, 2) +
        scaled(data.other_costs, 2);

    return { items: centsToString(items), total: centsToString(total) };
}

function addDays(date: string, days: number): string {
    const value = new Date(`${date}T12:00:00`);
    value.setDate(value.getDate() + days);

    return value.toISOString().slice(0, 10);
}

export function initialOrderData(
    order: PurchaseOrder | null,
    today: string,
    idempotencyKey: string,
): OrderFormData {
    return {
        supplier_profile_id: order?.supplier_profile_id ?? null,
        order_date: order?.order_date ?? today,
        expected_date: order?.expected_date ?? '',
        external_reference: order?.external_reference ?? '',
        notes: order?.notes ?? '',
        discount: toDecimalInput(order?.discount ?? '0', 2),
        freight: toDecimalInput(order?.freight ?? '0', 2),
        insurance: toDecimalInput(order?.insurance ?? '0', 2),
        other_costs: toDecimalInput(order?.other_costs ?? '0', 2),
        payment_condition: order?.payment_condition.value ?? 'undefined',
        payment_method: order?.payment_method?.value ?? '',
        payment_notes: order?.payment_notes ?? '',
        installments: (order?.payment_schedules ?? []).map((schedule) => ({
            due_date: schedule.due_date,
            amount: toDecimalInput(schedule.amount, 2),
            method: schedule.method_value ?? '',
        })),
        items: (order?.items ?? []).map((item) => ({
            id: item.id,
            product_variant_id: item.product_variant_id,
            description: item.description,
            unit_code: item.unit_code,
            allows_fraction: item.allows_fraction,
            supplier_code: item.supplier_code,
            quantity: toDecimalInput(item.quantity),
            unit_cost: toDecimalInput(item.unit_cost ?? '0', 2),
            discount: toDecimalInput(item.discount ?? '0', 2),
            is_free: item.is_free,
        })),
        idempotency_key: idempotencyKey,
    };
}

/** Converte os campos digitados para o formato da API. */
export function serializeOrder<T extends OrderFormData>(data: T): T {
    return {
        ...data,
        expected_date: data.expected_date || null,
        payment_method: data.payment_method || null,
        discount: parseDecimal(data.discount || '0'),
        freight: parseDecimal(data.freight || '0'),
        insurance: parseDecimal(data.insurance || '0'),
        other_costs: parseDecimal(data.other_costs || '0'),
        installments: data.installments.map((row) => ({
            ...row,
            amount: parseDecimal(row.amount || '0'),
            method: row.method || null,
        })),
        items: data.items.map((row) => ({
            ...row,
            quantity: parseDecimal(row.quantity || '0'),
            unit_cost: parseDecimal(row.unit_cost || '0'),
            discount: parseDecimal(row.discount || '0'),
        })),
    } as unknown as T;
}

function Money({
    id,
    label,
    value,
    error,
    disabled,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <FormField id={id} label={label} error={error}>
            <Input
                id={id}
                inputMode="decimal"
                disabled={disabled}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormField>
    );
}

/**
 * Itens, valores e condição de pagamento do pedido. Sem `manage_cost`, só itens e quantidades são editáveis.
 */
export default function OrderEditor({
    form,
    conditions,
    methods,
    canManageCost,
}: {
    form: InertiaFormProps<OrderFormData>;
    conditions: Option[];
    methods: Option[];
    canManageCost: boolean;
}) {
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;
    const totals = orderTotals(data);
    const [installmentCount, setInstallmentCount] = useState('2');
    const [intervalDays, setIntervalDays] = useState('30');
    const needsSchedule = !['undefined', 'cash'].includes(
        data.payment_condition,
    );

    function setItem(index: number, changes: Partial<OrderItemRow>) {
        setData(
            'items',
            data.items.map((row, rowIndex) =>
                rowIndex === index ? { ...row, ...changes } : row,
            ),
        );
    }

    function addItem(item: PurchasableItem) {
        const existing = data.items.findIndex(
            (row) => row.product_variant_id === item.id,
        );

        if (existing >= 0) {
            document.getElementById(`item-quantity-${existing}`)?.focus();

            return;
        }

        setData('items', [
            ...data.items,
            {
                id: null,
                product_variant_id: item.id,
                description: item.name,
                unit_code: item.unit.code,
                allows_fraction: item.unit.allows_fraction,
                supplier_code: item.supplier_code,
                quantity: '1',
                unit_cost: toDecimalInput(item.last_cost, 2),
                discount: '0',
                is_free: false,
            },
        ]);
        window.setTimeout(() =>
            document
                .getElementById(`item-quantity-${data.items.length}`)
                ?.focus(),
        );
    }

    /** Divide o total em parcelas iguais; a diferença de centavos fica na última. */
    function generateInstallments() {
        const count = Math.max(1, Math.min(60, Number(installmentCount) || 1));
        const total = scaled(totals.total, 2);
        const share = total / BigInt(count);
        const days = Number(intervalDays) || 30;
        const isEntry = data.payment_condition === 'entry_installments';

        setData(
            'installments',
            Array.from({ length: count }, (_, index) => ({
                due_date: addDays(
                    data.order_date,
                    isEntry ? index * days : (index + 1) * days,
                ),
                amount: toDecimalInput(
                    centsToString(
                        index === count - 1
                            ? total - share * BigInt(count - 1)
                            : share,
                    ),
                    2,
                ),
                method: data.payment_method,
            })),
        );
    }

    return (
        <div className="space-y-6">
            <section
                className="space-y-3 rounded-lg border p-4"
                aria-labelledby="items-heading"
            >
                <h3 id="items-heading" className="font-medium">
                    Itens
                </h3>
                <RemoteLookup<PurchasableItem>
                    id="item-lookup"
                    placeholder="Nome, SKU, código de barras ou código do fornecedor"
                    url={(term) =>
                        itemLookup.url({
                            query: {
                                term,
                                ...(data.supplier_profile_id
                                    ? { supplier: data.supplier_profile_id }
                                    : {}),
                            },
                        })
                    }
                    render={(item) => (
                        <>
                            {item.name}
                            <span className="text-muted-foreground">
                                {item.sku && ` · ${item.sku}`}
                                {item.supplier_code &&
                                    ` · Forn.: ${item.supplier_code}`}
                                {item.is_service && ' · Serviço'}
                            </span>
                        </>
                    )}
                    onSelect={addItem}
                />
                <InputError message={errors.items} />
                {data.items.length > 0 && (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="p-2">Item</th>
                                    <th className="w-28 p-2">Quantidade</th>
                                    {canManageCost && (
                                        <>
                                            <th className="w-28 p-2">
                                                Custo unit.
                                            </th>
                                            <th className="w-24 p-2">
                                                Desconto
                                            </th>
                                            <th className="w-24 p-2">
                                                Bonificado
                                            </th>
                                            <th className="w-28 p-2 text-right">
                                                Total
                                            </th>
                                        </>
                                    )}
                                    <th className="w-10 p-2">
                                        <span className="sr-only">Remover</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.items.map((row, index) => (
                                    <tr
                                        key={row.product_variant_id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-2">
                                            {row.description}
                                            <span className="block text-xs text-muted-foreground">
                                                {row.unit_code}
                                                {row.supplier_code &&
                                                    ` · Cód. fornecedor ${row.supplier_code}`}
                                            </span>
                                            <InputError
                                                message={
                                                    errors[
                                                        `items.${index}.product_variant_id`
                                                    ]
                                                }
                                            />
                                        </td>
                                        <td className="p-2">
                                            <Input
                                                id={`item-quantity-${index}`}
                                                aria-label={`Quantidade de ${row.description}`}
                                                inputMode="decimal"
                                                value={row.quantity}
                                                onChange={(event) =>
                                                    setItem(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `items.${index}.quantity`
                                                    ]
                                                }
                                            />
                                        </td>
                                        {canManageCost && (
                                            <>
                                                <td className="p-2">
                                                    <Input
                                                        aria-label={`Custo unitário de ${row.description}`}
                                                        inputMode="decimal"
                                                        disabled={row.is_free}
                                                        value={
                                                            row.is_free
                                                                ? '0'
                                                                : row.unit_cost
                                                        }
                                                        onChange={(event) =>
                                                            setItem(index, {
                                                                unit_cost:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `items.${index}.unit_cost`
                                                            ]
                                                        }
                                                    />
                                                </td>
                                                <td className="p-2">
                                                    <Input
                                                        aria-label={`Desconto de ${row.description}`}
                                                        inputMode="decimal"
                                                        disabled={row.is_free}
                                                        value={
                                                            row.is_free
                                                                ? '0'
                                                                : row.discount
                                                        }
                                                        onChange={(event) =>
                                                            setItem(index, {
                                                                discount:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `items.${index}.discount`
                                                            ]
                                                        }
                                                    />
                                                </td>
                                                <td className="p-2">
                                                    <CheckboxField
                                                        id={`item-free-${index}`}
                                                        label="Sim"
                                                        checked={row.is_free}
                                                        onChange={(checked) =>
                                                            setItem(index, {
                                                                is_free:
                                                                    checked,
                                                            })
                                                        }
                                                    />
                                                </td>
                                                <td className="p-2 text-right whitespace-nowrap">
                                                    {formatMoney(
                                                        centsToString(
                                                            itemCents(row),
                                                        ),
                                                    )}
                                                </td>
                                            </>
                                        )}
                                        <td className="p-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remover ${row.description}`}
                                                onClick={() =>
                                                    setData(
                                                        'items',
                                                        data.items.filter(
                                                            (_, rowIndex) =>
                                                                rowIndex !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {canManageCost ? (
                <section
                    className="space-y-3 rounded-lg border p-4"
                    aria-labelledby="values-heading"
                >
                    <h3 id="values-heading" className="font-medium">
                        Valores
                    </h3>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Money
                            id="order-discount"
                            label="Desconto geral"
                            value={data.discount}
                            error={errors.discount}
                            disabled={false}
                            onChange={(value) => setData('discount', value)}
                        />
                        <Money
                            id="order-freight"
                            label="Frete"
                            value={data.freight}
                            error={errors.freight}
                            disabled={false}
                            onChange={(value) => setData('freight', value)}
                        />
                        <Money
                            id="order-insurance"
                            label="Seguro"
                            value={data.insurance}
                            error={errors.insurance}
                            disabled={false}
                            onChange={(value) => setData('insurance', value)}
                        />
                        <Money
                            id="order-other"
                            label="Outras despesas"
                            value={data.other_costs}
                            error={errors.other_costs}
                            disabled={false}
                            onChange={(value) => setData('other_costs', value)}
                        />
                    </div>
                    <dl className="flex flex-wrap justify-end gap-6 text-sm">
                        <div>
                            <dt className="text-muted-foreground">
                                Total dos itens
                            </dt>
                            <dd className="font-medium">
                                {formatMoney(totals.items)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Total do pedido
                            </dt>
                            <dd className="text-lg font-semibold">
                                {formatMoney(totals.total)}
                            </dd>
                        </div>
                    </dl>
                    <p className="text-xs text-muted-foreground">
                        Frete, seguro, despesas e desconto geral são rateados
                        entre os itens pelo valor líquido e compõem o custo de
                        aquisição.
                    </p>
                </section>
            ) : (
                <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                    Custos, valores e condições de pagamento são informados por
                    quem tem permissão para gerenciar custos.
                </p>
            )}

            {canManageCost && (
                <section
                    className="space-y-3 rounded-lg border p-4"
                    aria-labelledby="payment-heading"
                >
                    <h3 id="payment-heading" className="font-medium">
                        Condição de pagamento prevista
                    </h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="payment-condition"
                            label="Condição"
                            error={errors.payment_condition}
                        >
                            <NativeSelect
                                id="payment-condition"
                                value={data.payment_condition}
                                onChange={(event) => {
                                    setData((current) => ({
                                        ...current,
                                        payment_condition: event.target.value,
                                        installments: [],
                                    }));
                                }}
                            >
                                {conditions.map((condition) => (
                                    <option
                                        key={condition.value}
                                        value={condition.value}
                                    >
                                        {condition.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="payment-method"
                            label="Forma prevista"
                            error={errors.payment_method}
                        >
                            <NativeSelect
                                id="payment-method"
                                value={data.payment_method}
                                onChange={(event) =>
                                    setData(
                                        'payment_method',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Não informada</option>
                                {methods.map((method) => (
                                    <option
                                        key={method.value}
                                        value={method.value}
                                    >
                                        {method.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
                    {data.payment_condition === 'cash' && (
                        <p className="text-sm text-muted-foreground">
                            Vencimento único na data do pedido, no valor total.
                        </p>
                    )}
                    {needsSchedule && (
                        <div className="space-y-3">
                            {data.payment_condition !== 'term' && (
                                <div className="flex flex-wrap items-end gap-2">
                                    <FormField
                                        id="installment-count"
                                        label="Vencimentos"
                                    >
                                        <Input
                                            id="installment-count"
                                            className="w-24"
                                            inputMode="numeric"
                                            value={installmentCount}
                                            onChange={(event) =>
                                                setInstallmentCount(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="installment-interval"
                                        label="Intervalo (dias)"
                                    >
                                        <Input
                                            id="installment-interval"
                                            className="w-28"
                                            inputMode="numeric"
                                            value={intervalDays}
                                            onChange={(event) =>
                                                setIntervalDays(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </FormField>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={generateInstallments}
                                    >
                                        Gerar vencimentos
                                    </Button>
                                </div>
                            )}
                            {data.payment_condition === 'term' &&
                                data.installments.length === 0 && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setData('installments', [
                                                {
                                                    due_date: addDays(
                                                        data.order_date,
                                                        30,
                                                    ),
                                                    amount: toDecimalInput(
                                                        totals.total,
                                                        2,
                                                    ),
                                                    method: data.payment_method,
                                                },
                                            ])
                                        }
                                    >
                                        Definir vencimento
                                    </Button>
                                )}
                            {data.installments.map((row, index) => (
                                <div
                                    key={index}
                                    className="grid items-start gap-2 sm:grid-cols-[3rem_1fr_1fr_auto]"
                                >
                                    <span className="pt-2 text-sm text-muted-foreground">
                                        {data.payment_condition ===
                                            'entry_installments' && index === 0
                                            ? 'Entrada'
                                            : `${index + 1}ª`}
                                    </span>
                                    <Input
                                        type="date"
                                        aria-label={`Vencimento ${index + 1}`}
                                        value={row.due_date}
                                        onChange={(event) =>
                                            setData(
                                                'installments',
                                                data.installments.map(
                                                    (item, itemIndex) =>
                                                        itemIndex === index
                                                            ? {
                                                                  ...item,
                                                                  due_date:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label={`Valor ${index + 1}`}
                                        inputMode="decimal"
                                        value={row.amount}
                                        onChange={(event) =>
                                            setData(
                                                'installments',
                                                data.installments.map(
                                                    (item, itemIndex) =>
                                                        itemIndex === index
                                                            ? {
                                                                  ...item,
                                                                  amount: event
                                                                      .target
                                                                      .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remover vencimento ${index + 1}`}
                                        onClick={() =>
                                            setData(
                                                'installments',
                                                data.installments.filter(
                                                    (_, itemIndex) =>
                                                        itemIndex !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                    <InputError message={errors.installments} />
                    <FormField
                        id="payment-notes"
                        label="Observações do pagamento"
                        error={errors.payment_notes}
                    >
                        <Input
                            id="payment-notes"
                            maxLength={500}
                            value={data.payment_notes}
                            onChange={(event) =>
                                setData('payment_notes', event.target.value)
                            }
                        />
                    </FormField>
                    <p className="text-xs text-muted-foreground">
                        Previsão comercial: não representa pagamento efetuado
                        nem gera cobrança.
                    </p>
                </section>
            )}

            <FormField
                id="order-notes"
                label="Observações"
                error={errors.notes}
            >
                <NativeTextarea
                    id="order-notes"
                    value={data.notes}
                    onChange={(event) => setData('notes', event.target.value)}
                />
            </FormField>
        </div>
    );
}
