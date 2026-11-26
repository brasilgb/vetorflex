import { Head, Link, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { parseDecimal, toDecimalInput } from '@/lib/decimal';
import { dashboard } from '@/routes/purchases';
import { index, show, store, update } from '@/routes/purchases/suppliers';
import type { Option } from '@/types';

type Address = {
    id?: number;
    type: string;
    postal_code: string | null;
    street: string | null;
    number: string | null;
    complement: string | null;
    district: string | null;
    city: string | null;
    state: string | null;
};
type Contact = {
    id?: number;
    name: string;
    position: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    is_primary?: boolean;
};

type SupplierForm = {
    id: number;
    type: string;
    name: string;
    trade_name: string | null;
    document: string | null;
    email: string | null;
    phone: string | null;
    mobile: string | null;
    whatsapp: string | null;
    state_registration: string | null;
    municipal_registration: string | null;
    internal_code: string | null;
    lead_time_days: number | null;
    payment_terms: string | null;
    minimum_order: string | null;
    notes: string | null;
    addresses: Address[];
    contacts: Contact[];
};

const TEXT_FIELDS = [
    ['name', 'Nome ou razão social', true],
    ['trade_name', 'Nome fantasia', false],
    ['document', 'CPF/CNPJ', false],
    ['state_registration', 'Inscrição estadual', false],
    ['municipal_registration', 'Inscrição municipal', false],
    ['email', 'E-mail', false],
    ['phone', 'Telefone', false],
    ['mobile', 'Celular', false],
    ['whatsapp', 'WhatsApp', false],
] as const;

const ADDRESS_FIELDS = [
    ['postal_code', 'CEP'],
    ['street', 'Logradouro'],
    ['number', 'Número'],
    ['complement', 'Complemento'],
    ['district', 'Bairro'],
    ['city', 'Cidade'],
    ['state', 'UF'],
] as const;

const CONTACT_FIELDS = [
    ['name', 'Nome'],
    ['position', 'Cargo'],
    ['email', 'E-mail'],
    ['phone', 'Telefone'],
    ['whatsapp', 'WhatsApp'],
] as const;

/** Apenas tipo e nome são obrigatórios: o cadastro simplificado pode ser completado depois. */
export default function SupplierFormPage({
    supplier,
    addressTypes,
}: {
    supplier: SupplierForm | null;
    addressTypes: Option[];
}) {
    const form = useForm({
        type: supplier?.type ?? 'company',
        name: supplier?.name ?? '',
        trade_name: supplier?.trade_name ?? '',
        document: supplier?.document ?? '',
        email: supplier?.email ?? '',
        phone: supplier?.phone ?? '',
        mobile: supplier?.mobile ?? '',
        whatsapp: supplier?.whatsapp ?? '',
        state_registration: supplier?.state_registration ?? '',
        municipal_registration: supplier?.municipal_registration ?? '',
        internal_code: supplier?.internal_code ?? '',
        lead_time_days: supplier?.lead_time_days?.toString() ?? '',
        payment_terms: supplier?.payment_terms ?? '',
        minimum_order: toDecimalInput(supplier?.minimum_order, 2),
        notes: supplier?.notes ?? '',
        addresses: supplier?.addresses ?? [],
        contacts: supplier?.contacts ?? [],
        link_existing: false,
    });
    const { data, setData } = form;
    const errors = form.errors as Record<string, string>;

    return (
        <>
            <Head
                title={supplier ? `Editar ${supplier.name}` : 'Novo fornecedor'}
            />
            <form
                className="mx-auto max-w-5xl space-y-6 p-4 md:p-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((current) => ({
                        ...current,
                        lead_time_days:
                            current.lead_time_days === ''
                                ? null
                                : current.lead_time_days,
                        minimum_order:
                            current.minimum_order === ''
                                ? null
                                : parseDecimal(current.minimum_order),
                    }));

                    if (supplier) {
                        form.put(update.url(supplier.id));
                    } else {
                        form.post(store.url());
                    }
                }}
            >
                <Heading
                    variant="small"
                    title={
                        supplier ? `Editar ${supplier.name}` : 'Novo fornecedor'
                    }
                    description="Dados cadastrais são compartilhados com Clientes quando a pessoa tem os dois papéis."
                />
                {errors.link_existing && (
                    <div className="space-y-2 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4">
                        <InputError message={errors.link_existing} />
                        <CheckboxField
                            id="link-existing"
                            label="Adicionar o papel fornecedor à pessoa existente, sem alterar seus dados"
                            checked={data.link_existing}
                            onChange={(checked) =>
                                setData('link_existing', checked)
                            }
                        />
                    </div>
                )}
                <section
                    className="grid gap-4 rounded-lg border p-4 md:grid-cols-2"
                    aria-label="Identificação"
                >
                    <FormField id="type" label="Tipo" error={errors.type}>
                        <NativeSelect
                            id="type"
                            value={data.type}
                            onChange={(event) =>
                                setData('type', event.target.value)
                            }
                        >
                            <option value="company">Pessoa jurídica</option>
                            <option value="individual">Pessoa física</option>
                        </NativeSelect>
                    </FormField>
                    {TEXT_FIELDS.map(([field, label, required]) => (
                        <FormField
                            key={field}
                            id={field}
                            label={label}
                            error={errors[field]}
                        >
                            <Input
                                id={field}
                                required={required}
                                value={data[field]}
                                onChange={(event) =>
                                    setData(field, event.target.value)
                                }
                            />
                        </FormField>
                    ))}
                </section>
                <section
                    className="grid gap-4 rounded-lg border p-4 md:grid-cols-2"
                    aria-label="Dados comerciais"
                >
                    <FormField
                        id="internal_code"
                        label="Código interno"
                        error={errors.internal_code}
                    >
                        <Input
                            id="internal_code"
                            value={data.internal_code}
                            onChange={(event) =>
                                setData('internal_code', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="lead_time_days"
                        label="Prazo médio de entrega (dias)"
                        error={errors.lead_time_days}
                    >
                        <Input
                            id="lead_time_days"
                            inputMode="numeric"
                            value={data.lead_time_days}
                            onChange={(event) =>
                                setData('lead_time_days', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="payment_terms"
                        label="Condição de pagamento preferencial"
                        error={errors.payment_terms}
                    >
                        <Input
                            id="payment_terms"
                            placeholder="Ex.: 30/60 dias, boleto"
                            value={data.payment_terms}
                            onChange={(event) =>
                                setData('payment_terms', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="minimum_order"
                        label="Pedido mínimo (R$)"
                        error={errors.minimum_order}
                    >
                        <Input
                            id="minimum_order"
                            inputMode="decimal"
                            value={data.minimum_order}
                            onChange={(event) =>
                                setData('minimum_order', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="notes"
                        label="Observações do fornecedor"
                        error={errors.notes}
                        className="md:col-span-2"
                    >
                        <NativeTextarea
                            id="notes"
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                </section>
                <section
                    className="space-y-3 rounded-lg border p-4"
                    aria-labelledby="addresses-heading"
                >
                    <div className="flex items-center justify-between">
                        <h3 id="addresses-heading" className="font-medium">
                            Endereços
                        </h3>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setData('addresses', [
                                    ...data.addresses,
                                    {
                                        type: 'main',
                                        postal_code: '',
                                        street: '',
                                        number: '',
                                        complement: '',
                                        district: '',
                                        city: '',
                                        state: '',
                                    },
                                ])
                            }
                        >
                            Adicionar endereço
                        </Button>
                    </div>
                    {data.addresses.map((address, index) => (
                        <div
                            key={address.id ?? `new-${index}`}
                            className="grid gap-2 border-t pt-3 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <NativeSelect
                                aria-label="Tipo de endereço"
                                value={address.type}
                                onChange={(event) =>
                                    setData(
                                        'addresses',
                                        data.addresses.map((item, itemIndex) =>
                                            itemIndex === index
                                                ? {
                                                      ...item,
                                                      type: event.target.value,
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            >
                                {addressTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            {ADDRESS_FIELDS.map(([field, label]) => (
                                <div key={field}>
                                    <Input
                                        aria-label={label}
                                        placeholder={label}
                                        maxLength={
                                            field === 'state' ? 2 : undefined
                                        }
                                        value={address[field] ?? ''}
                                        onChange={(event) =>
                                            setData(
                                                'addresses',
                                                data.addresses.map(
                                                    (item, itemIndex) =>
                                                        itemIndex === index
                                                            ? {
                                                                  ...item,
                                                                  [field]:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `addresses.${index}.${field}`
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="justify-self-start"
                                onClick={() =>
                                    setData(
                                        'addresses',
                                        data.addresses.filter(
                                            (_, itemIndex) =>
                                                itemIndex !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 className="size-4" /> Remover
                            </Button>
                        </div>
                    ))}
                </section>
                <section
                    className="space-y-3 rounded-lg border p-4"
                    aria-labelledby="contacts-heading"
                >
                    <div className="flex items-center justify-between">
                        <h3 id="contacts-heading" className="font-medium">
                            Contatos comerciais
                        </h3>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setData('contacts', [
                                    ...data.contacts,
                                    {
                                        name: '',
                                        position: '',
                                        email: '',
                                        phone: '',
                                        whatsapp: '',
                                    },
                                ])
                            }
                        >
                            Adicionar contato
                        </Button>
                    </div>
                    {data.contacts.map((contact, index) => (
                        <div
                            key={contact.id ?? `new-${index}`}
                            className="grid gap-2 border-t pt-3 sm:grid-cols-2 lg:grid-cols-5"
                        >
                            {CONTACT_FIELDS.map(([field, label]) => (
                                <div key={field}>
                                    <Input
                                        aria-label={label}
                                        placeholder={label}
                                        required={field === 'name'}
                                        value={contact[field] ?? ''}
                                        onChange={(event) =>
                                            setData(
                                                'contacts',
                                                data.contacts.map(
                                                    (item, itemIndex) =>
                                                        itemIndex === index
                                                            ? {
                                                                  ...item,
                                                                  [field]:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors[`contacts.${index}.${field}`]
                                        }
                                    />
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="justify-self-start"
                                onClick={() =>
                                    setData(
                                        'contacts',
                                        data.contacts.filter(
                                            (_, itemIndex) =>
                                                itemIndex !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 className="size-4" /> Remover
                            </Button>
                        </div>
                    ))}
                </section>
                <div className="flex justify-end gap-2">
                    <Button variant="ghost" asChild>
                        <Link href={supplier ? show(supplier.id) : index()}>
                            Cancelar
                        </Link>
                    </Button>
                    <Button disabled={form.processing}>Salvar</Button>
                </div>
            </form>
        </>
    );
}

SupplierFormPage.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Fornecedores', href: index() },
        { title: 'Cadastro', href: '#' },
    ],
};
