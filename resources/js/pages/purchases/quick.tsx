import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import OrderEditor, {
    initialOrderData,
    serializeOrder,
} from '@/components/purchases/order-editor';
import type { OrderFormData } from '@/components/purchases/order-editor';
import RemoteLookup from '@/components/purchases/remote-lookup';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { uuid } from '@/lib/uuid';
import { dashboard } from '@/routes/purchases';
import { index } from '@/routes/purchases/orders';
import { store } from '@/routes/purchases/quick';
import { lookup as supplierLookup } from '@/routes/purchases/suppliers';
import type { Option, SupplierSummary } from '@/types';

type NewSupplier = {
    type: string;
    name: string;
    trade_name: string;
    document: string;
    email: string;
    phone: string;
    whatsapp: string;
    link_existing: boolean;
};

type QuickFormData = OrderFormData & {
    document_reference: string;
    new_supplier: NewSupplier | null;
};

const EMPTY_SUPPLIER: NewSupplier = {
    type: 'company',
    name: '',
    trade_name: '',
    document: '',
    email: '',
    phone: '',
    whatsapp: '',
    link_existing: false,
};

/**
 * Registro de compra já realizada: o pedido é criado, confirmado e recebido integralmente de uma só vez.
 */
export default function QuickPurchase({
    conditions,
    methods,
    today,
    canManageSuppliers,
}: {
    conditions: Option[];
    methods: Option[];
    today: string;
    canManageSuppliers: boolean;
}) {
    const [supplier, setSupplier] = useState<SupplierSummary | null>(null);
    const form = useForm<QuickFormData>({
        ...initialOrderData(null, today, uuid()),
        payment_condition: 'cash',
        document_reference: '',
        new_supplier: null,
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;
    const setNewSupplier = (changes: Partial<NewSupplier>) =>
        setData('new_supplier', {
            ...(data.new_supplier ?? EMPTY_SUPPLIER),
            ...changes,
        });

    return (
        <>
            <Head title="Compra rápida" />
            <form
                className="mx-auto max-w-6xl space-y-6 p-4 md:p-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((current) => serializeOrder(current));
                    form.post(store.url());
                }}
            >
                <Heading
                    variant="small"
                    title="Compra rápida"
                    description="Para compras que já aconteceram: o estoque e o custo médio são atualizados ao salvar, pelo mesmo fluxo de pedido e recebimento."
                />
                <section
                    className="space-y-4 rounded-lg border p-4"
                    aria-labelledby="supplier-heading"
                >
                    <h3 id="supplier-heading" className="font-medium">
                        1. Fornecedor
                    </h3>
                    {data.new_supplier === null ? (
                        <>
                            {supplier ? (
                                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/50 p-2 text-sm">
                                    <strong>{supplier.name}</strong>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setSupplier(null);
                                            setData(
                                                'supplier_profile_id',
                                                null,
                                            );
                                        }}
                                    >
                                        Trocar
                                    </Button>
                                </div>
                            ) : (
                                <RemoteLookup<SupplierSummary>
                                    id="quick-supplier"
                                    placeholder="Nome, CPF/CNPJ ou código interno"
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
                            {canManageSuppliers && !supplier && (
                                <Button
                                    type="button"
                                    variant="link"
                                    className="px-0"
                                    onClick={() =>
                                        setData('new_supplier', EMPTY_SUPPLIER)
                                    }
                                >
                                    Cadastrar novo fornecedor
                                </Button>
                            )}
                        </>
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2">
                            <FormField
                                id="new-type"
                                label="Tipo"
                                error={errors['new_supplier.type']}
                            >
                                <NativeSelect
                                    id="new-type"
                                    value={data.new_supplier.type}
                                    onChange={(event) =>
                                        setNewSupplier({
                                            type: event.target.value,
                                        })
                                    }
                                >
                                    <option value="company">
                                        Pessoa jurídica
                                    </option>
                                    <option value="individual">
                                        Pessoa física
                                    </option>
                                </NativeSelect>
                            </FormField>
                            <FormField
                                id="new-name"
                                label="Nome ou razão social"
                                error={errors['new_supplier.name']}
                            >
                                <Input
                                    id="new-name"
                                    required
                                    value={data.new_supplier.name}
                                    onChange={(event) =>
                                        setNewSupplier({
                                            name: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            <FormField
                                id="new-document"
                                label="CPF/CNPJ (opcional)"
                                error={errors['new_supplier.document']}
                            >
                                <Input
                                    id="new-document"
                                    value={data.new_supplier.document}
                                    onChange={(event) =>
                                        setNewSupplier({
                                            document: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            <FormField
                                id="new-phone"
                                label="Telefone"
                                error={errors['new_supplier.phone']}
                            >
                                <Input
                                    id="new-phone"
                                    value={data.new_supplier.phone}
                                    onChange={(event) =>
                                        setNewSupplier({
                                            phone: event.target.value,
                                        })
                                    }
                                />
                            </FormField>
                            {errors.link_existing && (
                                <div className="md:col-span-2">
                                    <InputError
                                        message={errors.link_existing}
                                    />
                                    <CheckboxField
                                        id="new-link"
                                        label="Usar a pessoa existente como fornecedora"
                                        checked={
                                            data.new_supplier.link_existing
                                        }
                                        onChange={(checked) =>
                                            setNewSupplier({
                                                link_existing: checked,
                                            })
                                        }
                                    />
                                </div>
                            )}
                            <Button
                                type="button"
                                variant="link"
                                className="justify-self-start px-0"
                                onClick={() => setData('new_supplier', null)}
                            >
                                Selecionar fornecedor existente
                            </Button>
                        </div>
                    )}
                    <InputError message={errors.supplier_profile_id} />
                </section>
                <section
                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-2"
                    aria-label="Dados da compra"
                >
                    <FormField
                        id="quick-date"
                        label="Data da compra e do recebimento"
                        error={errors.order_date}
                    >
                        <Input
                            id="quick-date"
                            type="date"
                            required
                            max={today}
                            value={data.order_date}
                            onChange={(event) =>
                                setData('order_date', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="quick-document"
                        label="Nota ou documento do fornecedor"
                        error={errors.document_reference}
                    >
                        <Input
                            id="quick-document"
                            maxLength={100}
                            value={data.document_reference}
                            onChange={(event) =>
                                setData(
                                    'document_reference',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                </section>
                <OrderEditor
                    form={form as never}
                    conditions={conditions}
                    methods={methods}
                    canManageCost
                />
                <InputError
                    message={
                        errors.idempotency_key ?? errors.items ?? errors.status
                    }
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" asChild>
                        <Link href={index()}>Cancelar</Link>
                    </Button>
                    <Button disabled={form.processing}>
                        Registrar compra e receber
                    </Button>
                </div>
            </form>
        </>
    );
}

QuickPurchase.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Compra rápida', href: '#' },
    ],
};
