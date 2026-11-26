import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import OrderEditor, {
    initialOrderData,
    serializeOrder,
} from '@/components/purchases/order-editor';
import RemoteLookup from '@/components/remote-lookup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { uuid } from '@/lib/uuid';
import { dashboard } from '@/routes/purchases';
import { index, show, store, update } from '@/routes/purchases/orders';
import { lookup as supplierLookup } from '@/routes/purchases/suppliers';
import type { Option, PurchaseOrder, SupplierSummary } from '@/types';

export default function OrderForm({
    order,
    selectedSupplier,
    conditions,
    methods,
    canManageCost,
    today,
}: {
    order: PurchaseOrder | null;
    selectedSupplier: SupplierSummary | null;
    conditions: Option[];
    methods: Option[];
    canManageCost: boolean;
    today: string;
}) {
    const [supplier, setSupplier] = useState<SupplierSummary | null>(
        selectedSupplier,
    );
    const form = useForm({
        ...initialOrderData(order, today, uuid()),
        supplier_profile_id: selectedSupplier?.id ?? null,
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head
                title={
                    order ? `Pedido #${order.number}` : 'Novo pedido de compra'
                }
            />
            <form
                className="mx-auto max-w-6xl space-y-6 p-4 md:p-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((current) => serializeOrder(current));

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
                        order
                            ? `Editar pedido #${order.number}`
                            : 'Novo pedido de compra'
                    }
                    description="O rascunho não movimenta estoque. Confirme o pedido quando estiver pronto para envio ao fornecedor."
                />
                <section
                    className="grid gap-4 rounded-lg border p-4 md:grid-cols-2"
                    aria-label="Dados gerais"
                >
                    <FormField
                        id="supplier-lookup"
                        label="Fornecedor"
                        error={errors.supplier_profile_id}
                        className="md:col-span-2"
                    >
                        {supplier && (
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/50 p-2 text-sm">
                                <span>
                                    <strong>{supplier.name}</strong>
                                    {supplier.document && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {supplier.document}
                                        </span>
                                    )}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSupplier(null);
                                        setData('supplier_profile_id', null);
                                    }}
                                >
                                    Trocar
                                </Button>
                            </div>
                        )}
                        {!supplier && (
                            <RemoteLookup<SupplierSummary>
                                id="supplier-lookup"
                                placeholder="Nome, nome fantasia, CPF/CNPJ ou código interno"
                                url={(term) =>
                                    supplierLookup.url({ query: { term } })
                                }
                                render={(item) => (
                                    <>
                                        {item.name}
                                        <span className="text-muted-foreground">
                                            {item.document &&
                                                ` · ${item.document}`}
                                        </span>
                                    </>
                                )}
                                onSelect={(item) => {
                                    setSupplier(item);
                                    setData('supplier_profile_id', item.id);
                                }}
                            />
                        )}
                    </FormField>
                    <FormField
                        id="order-date"
                        label="Data do pedido"
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
                        id="external-reference"
                        label="Referência externa"
                        error={errors.external_reference}
                        hint="Número do orçamento ou pedido no fornecedor, opcional."
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
                <OrderEditor
                    form={form}
                    conditions={conditions}
                    methods={methods}
                    canManageCost={canManageCost}
                />
                <InputError message={errors.idempotency_key ?? errors.status} />
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" asChild>
                        <Link href={order ? show(order.id) : index()}>
                            Cancelar
                        </Link>
                    </Button>
                    <Button disabled={form.processing}>
                        {order ? 'Salvar alterações' : 'Salvar rascunho'}
                    </Button>
                </div>
            </form>
        </>
    );
}

OrderForm.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Pedidos de compra', href: index() },
        { title: 'Pedido', href: '#' },
    ],
};
