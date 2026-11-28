import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import CustomerPicker from '@/components/sales/customer-picker';
import SaleEditor, {
    initialSaleData,
    serializeSale,
} from '@/components/sales/sale-editor';
import type { SaleFormOptions } from '@/components/sales/sale-form-options';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { uuid } from '@/lib/uuid';
import { dashboard } from '@/routes/sales';
import { index, show, store, update } from '@/routes/sales/orders';
import type { SaleCustomer, SaleOrder } from '@/types';

/**
 * Venda direta. Com "venda rápida", a venda é confirmada, atendida e concluída ao salvar, numa única operação.
 */
export default function SaleOrderForm({
    order,
    quick,
    ...options
}: SaleFormOptions & { order: SaleOrder | null; quick: boolean }) {
    const [customer, setCustomer] = useState<SaleCustomer | null>(
        options.selectedCustomer,
    );
    const form = useForm({
        ...initialSaleData(order, options.currentUserId, uuid()),
        order_date: order?.order_date ?? options.today,
        expected_date: order?.expected_date ?? '',
        external_reference: order?.external_reference ?? '',
        internal_notes: order?.internal_notes ?? '',
        delivery_method: order?.delivery_method.value ?? 'pickup',
        delivery_address_id: order?.delivery_address?.party_address_id ?? null,
        delivery_address_text:
            order?.delivery_address &&
            order.delivery_address.party_address_id === null
                ? order.delivery_address.text
                : '',
        quick: quick && options.abilities.quickSale && order === null,
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head title={order ? `Venda #${order.number}` : 'Nova venda'} />
            <form
                className="mx-auto max-w-6xl space-y-6 p-4 md:p-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((current) => ({
                        ...(serializeSale(current) as typeof current),
                        expected_date: current.expected_date || null,
                        delivery_address_text:
                            current.delivery_address_text || null,
                    }));

                    if (order) {
                        form.put(update.url(order.id));
                    } else {
                        form.post(store.url());
                    }
                }}
            >
                <Heading
                    variant="small"
                    title={
                        order ? `Editar venda #${order.number}` : 'Nova venda'
                    }
                    description={
                        data.quick
                            ? 'Venda de balcão: ao registrar, a venda é confirmada, atendida e concluída.'
                            : 'O rascunho não reserva estoque. Confirme a venda para reservar as mercadorias.'
                    }
                />
                <section
                    className="grid gap-4 rounded-lg border p-4 md:grid-cols-4"
                    aria-label="Dados gerais"
                >
                    <FormField
                        id="customer-lookup"
                        label="Cliente"
                        className="md:col-span-4"
                    >
                        <CustomerPicker
                            customer={customer}
                            canRegister={options.abilities.registerCustomers}
                            allowsAnonymous={options.allowsAnonymous}
                            error={errors.customer_party_id}
                            onChange={(selected) => {
                                setCustomer(selected);
                                setData((current) => ({
                                    ...current,
                                    customer_party_id: selected?.id ?? null,
                                    delivery_address_id: null,
                                }));
                            }}
                        />
                    </FormField>
                    <FormField
                        id="customer-contact"
                        label="Contato"
                        error={errors.customer_contact}
                    >
                        <Input
                            id="customer-contact"
                            maxLength={255}
                            value={data.customer_contact}
                            onChange={(event) =>
                                setData('customer_contact', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="seller"
                        label="Responsável"
                        error={errors.seller_id}
                    >
                        <NativeSelect
                            id="seller"
                            value={data.seller_id ?? ''}
                            onChange={(event) =>
                                setData('seller_id', Number(event.target.value))
                            }
                        >
                            {options.sellers.map((seller) => (
                                <option key={seller.id} value={seller.id}>
                                    {seller.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="order-date"
                        label="Data da venda"
                        error={errors.order_date}
                    >
                        <Input
                            id="order-date"
                            type="date"
                            required
                            value={data.order_date}
                            onChange={(event) =>
                                setData('order_date', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="expected-date"
                        label="Previsão de entrega"
                        error={errors.expected_date}
                    >
                        <Input
                            id="expected-date"
                            type="date"
                            value={data.expected_date}
                            onChange={(event) =>
                                setData('expected_date', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="delivery-method"
                        label="Atendimento"
                        error={errors.delivery_method}
                    >
                        <NativeSelect
                            id="delivery-method"
                            value={data.delivery_method}
                            onChange={(event) =>
                                setData('delivery_method', event.target.value)
                            }
                        >
                            {options.deliveryMethods.map((method) => (
                                <option key={method.value} value={method.value}>
                                    {method.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    {data.delivery_method === 'delivery' && (
                        <FormField
                            id="delivery-address"
                            label="Endereço de entrega"
                            error={errors.delivery_address_id}
                            className="md:col-span-3"
                        >
                            {customer && customer.addresses.length > 0 && (
                                <NativeSelect
                                    id="delivery-address"
                                    value={data.delivery_address_id ?? ''}
                                    onChange={(event) =>
                                        setData(
                                            'delivery_address_id',
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                        )
                                    }
                                >
                                    <option value="">Outro endereço</option>
                                    {customer.addresses.map((address) => (
                                        <option
                                            key={address.id}
                                            value={address.id}
                                        >
                                            {address.text}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                            {data.delivery_address_id === null && (
                                <Input
                                    aria-label="Endereço de entrega"
                                    placeholder="Rua, número, bairro, cidade/UF"
                                    maxLength={500}
                                    value={data.delivery_address_text}
                                    onChange={(event) =>
                                        setData(
                                            'delivery_address_text',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                    )}
                    <FormField
                        id="external-reference"
                        label="Referência externa"
                        error={errors.external_reference}
                    >
                        <Input
                            id="external-reference"
                            maxLength={100}
                            value={data.external_reference}
                            onChange={(event) =>
                                setData(
                                    'external_reference',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                </section>
                <SaleEditor
                    form={form}
                    conditions={options.conditions}
                    methods={options.methods}
                    abilities={options.abilities}
                    baseDate={data.order_date}
                />
                <FormField
                    id="internal-notes"
                    label="Observações internas (não aparecem para o cliente)"
                    error={errors.internal_notes}
                >
                    <NativeTextarea
                        id="internal-notes"
                        maxLength={5000}
                        value={data.internal_notes}
                        onChange={(event) =>
                            setData('internal_notes', event.target.value)
                        }
                    />
                </FormField>
                {order === null && options.abilities.quickSale && (
                    <CheckboxField
                        id="quick-sale"
                        label="Venda rápida: confirmar, atender e concluir ao registrar"
                        checked={data.quick}
                        onChange={(checked) => setData('quick', checked)}
                    />
                )}
                <InputError
                    message={
                        errors.idempotency_key ??
                        errors.status ??
                        errors.quantity
                    }
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" asChild>
                        <Link href={order ? show(order.id) : index()}>
                            Cancelar
                        </Link>
                    </Button>
                    <Button disabled={form.processing}>
                        {order
                            ? 'Salvar alterações'
                            : data.quick
                              ? 'Registrar venda'
                              : 'Salvar rascunho'}
                    </Button>
                </div>
            </form>
        </>
    );
}

SaleOrderForm.layout = {
    breadcrumbs: [
        { title: 'Vendas', href: dashboard() },
        { title: 'Pedidos de venda', href: index() },
        { title: 'Venda', href: '#' },
    ],
};
