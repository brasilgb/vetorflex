import type { InertiaFormProps } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import RemoteLookup from '@/components/remote-lookup';
import QuickItemDialog from '@/components/sales/quick-item-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatDecimal,
    formatMoney,
    parseDecimal,
    toDecimalInput,
} from '@/lib/decimal';
import { lookup as itemLookup } from '@/routes/sales/items';
import type {
    Option,
    SaleAbilities,
    SaleItem,
    SaleOrder,
    SaleQuote,
    SellableItem,
} from '@/types';

export type SaleItemRow = {
    key: string;
    id: number | null;
    product_variant_id: number | null;
    description: string;
    unit_code: string;
    allows_fraction: boolean;
    is_service: boolean;
    quantity: string;
    unit_price: string;
    discount_percent: string;
    discount: string;
    notes: string;
    available: string | null;
};

export type InstallmentRow = {
    due_date: string;
    amount: string;
    method: string;
};

export type SaleFormData = {
    customer_party_id: number | null;
    customer_contact: string;
    seller_id: number | null;
    commercial_terms: string;
    notes: string;
    discount: string;
    freight: string;
    other_costs: string;
    payment_condition: string;
    payment_method: string;
    payment_notes: string;
    installments: InstallmentRow[];
    items: SaleItemRow[];
    idempotency_key: string;
};

/** Valores como inteiros na escala informada, sem ponto flutuante. */
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

/** Prévia do total do item; o valor definitivo é calculado pelo servidor. */
export function itemCents(row: SaleItemRow): bigint {
    const gross =
        (scaled(row.quantity, 4) * scaled(row.unit_price, 2) + 5000n) / 10000n;
    const percent = scaled(row.discount_percent, 2);
    const discount =
        percent > 0n
            ? (gross * percent + 5000n) / 10000n
            : scaled(row.discount, 2);

    return gross - discount;
}

export function saleTotals(data: SaleFormData): {
    items: string;
    total: string;
} {
    const items = data.items.reduce((sum, row) => sum + itemCents(row), 0n);
    const total =
        items -
        scaled(data.discount, 2) +
        scaled(data.freight, 2) +
        scaled(data.other_costs, 2);

    return { items: centsToString(items), total: centsToString(total) };
}

function addDays(date: string, days: number): string {
    const value = new Date(`${date}T12:00:00`);
    value.setDate(value.getDate() + days);

    return value.toISOString().slice(0, 10);
}

let rowSequence = 0;

function rowKey(): string {
    rowSequence += 1;

    return `row-${rowSequence}`;
}

export function initialSaleData(
    sale: SaleQuote | SaleOrder | null,
    currentUserId: number,
    idempotencyKey: string,
): SaleFormData {
    return {
        customer_party_id: sale?.customer_party_id ?? null,
        customer_contact: sale?.customer_snapshot?.contact ?? '',
        seller_id: sale?.seller_id ?? currentUserId,
        commercial_terms: sale?.commercial_terms ?? '',
        notes: sale?.notes ?? '',
        discount: toDecimalInput(sale?.discount ?? '0', 2),
        freight: toDecimalInput(sale?.freight ?? '0', 2),
        other_costs: toDecimalInput(sale?.other_costs ?? '0', 2),
        payment_condition: sale?.payment_condition.value ?? 'undefined',
        payment_method: sale?.payment_method?.value ?? '',
        payment_notes: sale?.payment_notes ?? '',
        installments: (sale?.payment_schedules ?? []).map((schedule) => ({
            due_date: schedule.due_date,
            amount: toDecimalInput(schedule.amount, 2),
            method: schedule.method_value ?? '',
        })),
        items: ((sale?.items ?? []) as SaleItem[]).map((item) => ({
            key: rowKey(),
            id: item.id,
            product_variant_id: item.product_variant_id,
            description: item.description,
            unit_code: item.unit_code,
            allows_fraction: item.allows_fraction,
            is_service: item.is_service,
            quantity: toDecimalInput(item.quantity),
            unit_price: toDecimalInput(item.unit_price, 2),
            discount_percent: toDecimalInput(item.discount_percent),
            discount: toDecimalInput(item.discount, 2),
            notes: item.notes ?? '',
            available: null,
        })),
        idempotency_key: idempotencyKey,
    };
}

/** Converte os campos digitados para o formato da API. Totais nunca são enviados. */
export function serializeSale<T extends SaleFormData>(
    data: T,
): Record<string, unknown> {
    return {
        ...data,
        payment_method: data.payment_method || null,
        customer_contact: data.customer_contact || null,
        discount: parseDecimal(data.discount || '0'),
        freight: parseDecimal(data.freight || '0'),
        other_costs: parseDecimal(data.other_costs || '0'),
        installments: data.installments.map((row) => ({
            ...row,
            amount: parseDecimal(row.amount || '0'),
            method: row.method || null,
        })),
        items: data.items.map((row) => ({
            id: row.id,
            product_variant_id: row.product_variant_id,
            ...(row.product_variant_id === null
                ? {
                      description: row.description,
                      unit_code: row.unit_code,
                      allows_fraction: row.allows_fraction,
                      is_service: row.is_service,
                  }
                : {}),
            quantity: parseDecimal(row.quantity || '0'),
            unit_price: parseDecimal(row.unit_price || ''),
            discount_percent: row.discount_percent
                ? parseDecimal(row.discount_percent)
                : null,
            discount: parseDecimal(row.discount || '0'),
            notes: row.notes || null,
        })),
    };
}

function MoneyField({
    id,
    label,
    value,
    error,
    disabled = false,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    disabled?: boolean;
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
 * Itens, valores e condição de pagamento de orçamentos e vendas. Campos sem permissão ficam bloqueados,
 * e o servidor também os ignora.
 */
export default function SaleEditor({
    form,
    conditions,
    methods,
    abilities,
    baseDate,
}: {
    form: InertiaFormProps<SaleFormData>;
    conditions: Option[];
    methods: Option[];
    abilities: SaleAbilities;
    baseDate: string;
}) {
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;
    const totals = saleTotals(data);
    const [installmentCount, setInstallmentCount] = useState('2');
    const [intervalDays, setIntervalDays] = useState('30');
    const needsSchedule = !['undefined', 'cash'].includes(
        data.payment_condition,
    );

    function setItem(index: number, changes: Partial<SaleItemRow>) {
        setData(
            'items',
            data.items.map((row, rowIndex) =>
                rowIndex === index ? { ...row, ...changes } : row,
            ),
        );
    }

    function focusQuantity(index: number) {
        window.setTimeout(() =>
            document.getElementById(`item-quantity-${index}`)?.focus(),
        );
    }

    function addItem(item: SellableItem) {
        const existing = data.items.findIndex(
            (row) => row.product_variant_id === item.id,
        );

        if (existing >= 0) {
            focusQuantity(existing);

            return;
        }

        setData('items', [
            ...data.items,
            {
                key: rowKey(),
                id: null,
                product_variant_id: item.id,
                description: item.name,
                unit_code: item.unit.code,
                allows_fraction: item.unit.allows_fraction,
                is_service: item.is_service,
                quantity: '1',
                unit_price: toDecimalInput(item.price, 2),
                discount_percent: '',
                discount: '0',
                notes: '',
                available: item.controls_stock ? item.available : null,
            },
        ]);
        focusQuantity(data.items.length);
    }

    function addCustomItem() {
        setData('items', [
            ...data.items,
            {
                key: rowKey(),
                id: null,
                product_variant_id: null,
                description: '',
                unit_code: 'UN',
                allows_fraction: false,
                is_service: false,
                quantity: '1',
                unit_price: '',
                discount_percent: '',
                discount: '0',
                notes: '',
                available: null,
            },
        ]);
        window.setTimeout(() =>
            document
                .getElementById(`item-description-${data.items.length}`)
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
                    baseDate,
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

    function setInstallment(index: number, changes: Partial<InstallmentRow>) {
        setData(
            'installments',
            data.installments.map((row, rowIndex) =>
                rowIndex === index ? { ...row, ...changes } : row,
            ),
        );
    }

    return (
        <div className="space-y-6">
            <section
                className="space-y-3 rounded-lg border p-4"
                aria-labelledby="items-heading"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 id="items-heading" className="font-medium">
                        Itens
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {abilities.registerItems && (
                            <QuickItemDialog onCreated={addItem} />
                        )}
                        {abilities.customItems && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addCustomItem}
                            >
                                Item avulso
                            </Button>
                        )}
                    </div>
                </div>
                <RemoteLookup<SellableItem>
                    id="item-lookup"
                    placeholder="Nome, SKU ou código de barras (Enter)"
                    url={(term) => itemLookup.url({ query: { term } })}
                    render={(item) => (
                        <>
                            {item.name}
                            <span className="text-muted-foreground">
                                {item.sku && ` · ${item.sku}`}
                                {item.price && ` · ${formatMoney(item.price)}`}
                                {item.is_service && ' · Serviço'}
                                {item.available !== null &&
                                    ` · Disponível: ${formatDecimal(item.available)} ${item.unit.code}`}
                            </span>
                        </>
                    )}
                    onSelect={addItem}
                />
                <InputError message={errors.items} />
                {data.items.length > 0 && (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="p-2">Item</th>
                                    <th className="w-24 p-2">Quantidade</th>
                                    <th className="w-28 p-2">Preço unit.</th>
                                    <th className="w-20 p-2">Desc. %</th>
                                    <th className="w-24 p-2">Desc. R$</th>
                                    <th className="w-28 p-2 text-right">
                                        Total
                                    </th>
                                    <th className="w-10 p-2">
                                        <span className="sr-only">Remover</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.items.map((row, index) => {
                                    const isCustom =
                                        row.product_variant_id === null;

                                    return (
                                        <tr
                                            key={row.key}
                                            className="border-t align-top"
                                        >
                                            <td className="space-y-1 p-2">
                                                {isCustom ? (
                                                    <div className="grid gap-1 sm:grid-cols-[1fr_5rem]">
                                                        <Input
                                                            id={`item-description-${index}`}
                                                            aria-label="Descrição do item avulso"
                                                            placeholder="Descrição do item avulso"
                                                            maxLength={255}
                                                            value={
                                                                row.description
                                                            }
                                                            onChange={(event) =>
                                                                setItem(index, {
                                                                    description:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                        />
                                                        <Input
                                                            aria-label="Unidade do item avulso"
                                                            maxLength={10}
                                                            value={
                                                                row.unit_code
                                                            }
                                                            onChange={(event) =>
                                                                setItem(index, {
                                                                    unit_code:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                        />
                                                        <CheckboxField
                                                            id={`item-service-${index}`}
                                                            label="Serviço"
                                                            checked={
                                                                row.is_service
                                                            }
                                                            onChange={(
                                                                checked,
                                                            ) =>
                                                                setItem(index, {
                                                                    is_service:
                                                                        checked,
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <>
                                                        {row.description}
                                                        <span className="block text-xs text-muted-foreground">
                                                            {row.unit_code}
                                                            {row.is_service &&
                                                                ' · Serviço'}
                                                            {row.available !==
                                                                null &&
                                                                ` · Disponível: ${formatDecimal(row.available)}`}
                                                        </span>
                                                    </>
                                                )}
                                                <Input
                                                    aria-label={`Observação de ${row.description || 'item'}`}
                                                    placeholder="Observação (opcional)"
                                                    className="h-8 text-xs"
                                                    maxLength={500}
                                                    value={row.notes}
                                                    onChange={(event) =>
                                                        setItem(index, {
                                                            notes: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `items.${index}.product_variant_id`
                                                        ] ??
                                                        errors[
                                                            `items.${index}.description`
                                                        ]
                                                    }
                                                />
                                            </td>
                                            <td className="p-2">
                                                <Input
                                                    id={`item-quantity-${index}`}
                                                    aria-label={`Quantidade de ${row.description || 'item'}`}
                                                    inputMode="decimal"
                                                    value={row.quantity}
                                                    onChange={(event) =>
                                                        setItem(index, {
                                                            quantity:
                                                                event.target
                                                                    .value,
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
                                            <td className="p-2">
                                                <Input
                                                    aria-label={`Preço unitário de ${row.description || 'item'}`}
                                                    inputMode="decimal"
                                                    disabled={
                                                        !abilities.changePrices &&
                                                        !isCustom
                                                    }
                                                    value={row.unit_price}
                                                    onChange={(event) =>
                                                        setItem(index, {
                                                            unit_price:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `items.${index}.unit_price`
                                                        ]
                                                    }
                                                />
                                            </td>
                                            <td className="p-2">
                                                <Input
                                                    aria-label={`Desconto percentual de ${row.description || 'item'}`}
                                                    inputMode="decimal"
                                                    disabled={
                                                        !abilities.applyDiscounts
                                                    }
                                                    value={row.discount_percent}
                                                    onChange={(event) =>
                                                        setItem(index, {
                                                            discount_percent:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `items.${index}.discount_percent`
                                                        ]
                                                    }
                                                />
                                            </td>
                                            <td className="p-2">
                                                <Input
                                                    aria-label={`Desconto em reais de ${row.description || 'item'}`}
                                                    inputMode="decimal"
                                                    disabled={
                                                        !abilities.applyDiscounts ||
                                                        row.discount_percent !==
                                                            ''
                                                    }
                                                    value={row.discount}
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
                                            <td className="p-2 text-right whitespace-nowrap">
                                                {formatMoney(
                                                    centsToString(
                                                        itemCents(row),
                                                    ),
                                                )}
                                            </td>
                                            <td className="p-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Remover ${row.description || 'item'}`}
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
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section
                className="space-y-3 rounded-lg border p-4"
                aria-labelledby="values-heading"
            >
                <h3 id="values-heading" className="font-medium">
                    Valores
                </h3>
                <div className="grid gap-3 sm:grid-cols-3">
                    <MoneyField
                        id="sale-discount"
                        label="Desconto geral"
                        value={data.discount}
                        error={errors.discount}
                        disabled={!abilities.applyDiscounts}
                        onChange={(value) => setData('discount', value)}
                    />
                    <MoneyField
                        id="sale-freight"
                        label="Frete"
                        value={data.freight}
                        error={errors.freight}
                        onChange={(value) => setData('freight', value)}
                    />
                    <MoneyField
                        id="sale-other"
                        label="Outras despesas"
                        value={data.other_costs}
                        error={errors.other_costs}
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
                        <dt className="text-muted-foreground">Total</dt>
                        <dd className="text-lg font-semibold">
                            {formatMoney(totals.total)}
                        </dd>
                    </div>
                </dl>
                <p className="text-xs text-muted-foreground">
                    Prévia. Os valores definitivos são calculados ao salvar.
                    {!abilities.changePrices && ' Os preços seguem o catálogo.'}
                </p>
            </section>

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
                            onChange={(event) =>
                                setData((current) => ({
                                    ...current,
                                    payment_condition: event.target.value,
                                    installments: [],
                                }))
                            }
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
                                setData('payment_method', event.target.value)
                            }
                        >
                            <option value="">Não informada</option>
                            {methods.map((method) => (
                                <option key={method.value} value={method.value}>
                                    {method.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                </div>
                {data.payment_condition === 'cash' && (
                    <p className="text-sm text-muted-foreground">
                        Vencimento único na data do documento, no valor total.
                    </p>
                )}
                {needsSchedule && (
                    <div className="space-y-3">
                        {data.payment_condition === 'term' ? (
                            data.installments.length === 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        setData('installments', [
                                            {
                                                due_date: addDays(baseDate, 30),
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
                            )
                        ) : (
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
                                            setIntervalDays(event.target.value)
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
                        {data.installments.map((row, index) => (
                            <div
                                key={index}
                                className="grid items-start gap-2 sm:grid-cols-[4rem_1fr_1fr_1fr_auto]"
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
                                        setInstallment(index, {
                                            due_date: event.target.value,
                                        })
                                    }
                                />
                                <Input
                                    aria-label={`Valor ${index + 1}`}
                                    inputMode="decimal"
                                    value={row.amount}
                                    onChange={(event) =>
                                        setInstallment(index, {
                                            amount: event.target.value,
                                        })
                                    }
                                />
                                <NativeSelect
                                    aria-label={`Forma do vencimento ${index + 1}`}
                                    value={row.method}
                                    onChange={(event) =>
                                        setInstallment(index, {
                                            method: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">Forma prevista</option>
                                    {methods.map((method) => (
                                        <option
                                            key={method.value}
                                            value={method.value}
                                        >
                                            {method.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remover vencimento ${index + 1}`}
                                    onClick={() =>
                                        setData(
                                            'installments',
                                            data.installments.filter(
                                                (_, rowIndex) =>
                                                    rowIndex !== index,
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
                    Previsão comercial: não representa pagamento recebido nem
                    gera cobrança.
                </p>
            </section>

            <div className="grid gap-4 md:grid-cols-2">
                <FormField
                    id="commercial-terms"
                    label="Condições comerciais"
                    error={errors.commercial_terms}
                >
                    <NativeTextarea
                        id="commercial-terms"
                        maxLength={5000}
                        value={data.commercial_terms}
                        onChange={(event) =>
                            setData('commercial_terms', event.target.value)
                        }
                    />
                </FormField>
                <FormField
                    id="sale-notes"
                    label="Observações ao cliente"
                    error={errors.notes}
                >
                    <NativeTextarea
                        id="sale-notes"
                        maxLength={5000}
                        value={data.notes}
                        onChange={(event) =>
                            setData('notes', event.target.value)
                        }
                    />
                </FormField>
            </div>
        </div>
    );
}
