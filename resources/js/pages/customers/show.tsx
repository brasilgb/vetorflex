import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

type Customer = {
    id: number;
    type: string;
    name: string;
    trade_name: string | null;
    document: string | null;
    email: string | null;
    phone: string | null;
    mobile: string | null;
    is_active: boolean;
    customer_profile: {
        credit_limit: string | null;
        payment_terms: string | null;
        classification: string | null;
        commercial_notes: string | null;
    } | null;
    addresses: {
        type: string;
        postal_code: string | null;
        street: string | null;
        number: string | null;
        city: string | null;
        state: string | null;
    }[];
    contacts: { name: string; email: string | null; phone: string | null }[];
};

export default function Customer({ customer }: { customer: Customer }) {
    const form = useForm({
        type: customer.type,
        name: customer.name,
        trade_name: customer.trade_name ?? '',
        document: customer.document ?? '',
        email: customer.email ?? '',
        phone: customer.phone ?? '',
        mobile: customer.mobile ?? '',
        credit_limit: customer.customer_profile?.credit_limit ?? '',
        payment_terms: customer.customer_profile?.payment_terms ?? '',
        classification: customer.customer_profile?.classification ?? '',
        commercial_notes: customer.customer_profile?.commercial_notes ?? '',
        addresses: customer.addresses,
        contacts: customer.contacts,
    });
    const setAddress = (index: number, field: string, value: string) =>
        form.setData(
            'addresses',
            form.data.addresses.map((item, itemIndex) =>
                itemIndex === index ? { ...item, [field]: value } : item,
            ),
        );
    const setContact = (index: number, field: string, value: string) =>
        form.setData(
            'contacts',
            form.data.contacts.map((item, itemIndex) =>
                itemIndex === index ? { ...item, [field]: value } : item,
            ),
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clientes', href: '/customers' },
                { title: customer.name, href: `/customers/${customer.id}` },
            ]}
        >
            <Head title={customer.name} />
            <main className="space-y-6 p-6">
                <div className="flex justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            {customer.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {customer.is_active
                                ? 'Cliente ativo'
                                : 'Cliente inativo'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            className="rounded-md border px-3 py-2 text-sm"
                            href="/customers"
                        >
                            Voltar
                        </Link>
                        <button
                            className="rounded-md border px-3 py-2 text-sm"
                            onClick={() =>
                                router.patch(
                                    `/customers/${customer.id}/${customer.is_active ? 'deactivate' : 'reactivate'}`,
                                )
                            }
                        >
                            {customer.is_active ? 'Inativar' : 'Reativar'}
                        </button>
                    </div>
                </div>
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/customers/${customer.id}`);
                    }}
                >
                    <section className="grid gap-3 rounded-lg border p-4 md:grid-cols-2">
                        <select
                            className="rounded-md border p-2"
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData('type', event.target.value)
                            }
                        >
                            <option value="individual">Pessoa física</option>
                            <option value="company">Pessoa jurídica</option>
                        </select>
                        <input
                            className="rounded-md border p-2"
                            placeholder="Nome"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        <input
                            className="rounded-md border p-2"
                            placeholder="CPF/CNPJ"
                            value={form.data.document}
                            onChange={(event) =>
                                form.setData('document', event.target.value)
                            }
                        />
                        <input
                            className="rounded-md border p-2"
                            placeholder="Nome fantasia"
                            value={form.data.trade_name}
                            onChange={(event) =>
                                form.setData('trade_name', event.target.value)
                            }
                        />
                        <input
                            className="rounded-md border p-2"
                            placeholder="E-mail"
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                        />
                        <input
                            className="rounded-md border p-2"
                            placeholder="Telefone"
                            value={form.data.phone}
                            onChange={(event) =>
                                form.setData('phone', event.target.value)
                            }
                        />
                    </section>
                    <section className="rounded-lg border p-4">
                        <div className="mb-3 flex justify-between">
                            <h2 className="font-medium">Endereços</h2>
                            <button
                                className="text-sm underline"
                                type="button"
                                onClick={() =>
                                    form.setData('addresses', [
                                        ...form.data.addresses,
                                        {
                                            type: 'main',
                                            postal_code: '',
                                            street: '',
                                            number: '',
                                            city: '',
                                            state: '',
                                        },
                                    ])
                                }
                            >
                                Adicionar
                            </button>
                        </div>
                        {form.data.addresses.map((address, index) => (
                            <div
                                className="mb-3 grid gap-2 md:grid-cols-3"
                                key={index}
                            >
                                <input
                                    className="rounded border p-2"
                                    placeholder="CEP"
                                    value={address.postal_code ?? ''}
                                    onChange={(event) =>
                                        setAddress(
                                            index,
                                            'postal_code',
                                            event.target.value,
                                        )
                                    }
                                />
                                <input
                                    className="rounded border p-2"
                                    placeholder="Rua"
                                    value={address.street ?? ''}
                                    onChange={(event) =>
                                        setAddress(
                                            index,
                                            'street',
                                            event.target.value,
                                        )
                                    }
                                />
                                <input
                                    className="rounded border p-2"
                                    placeholder="Número"
                                    value={address.number ?? ''}
                                    onChange={(event) =>
                                        setAddress(
                                            index,
                                            'number',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        ))}
                    </section>
                    <section className="rounded-lg border p-4">
                        <div className="mb-3 flex justify-between">
                            <h2 className="font-medium">Contatos</h2>
                            <button
                                className="text-sm underline"
                                type="button"
                                onClick={() =>
                                    form.setData('contacts', [
                                        ...form.data.contacts,
                                        { name: '', email: '', phone: '' },
                                    ])
                                }
                            >
                                Adicionar
                            </button>
                        </div>
                        {form.data.contacts.map((contact, index) => (
                            <div
                                className="mb-3 grid gap-2 md:grid-cols-3"
                                key={index}
                            >
                                <input
                                    className="rounded border p-2"
                                    placeholder="Nome"
                                    value={contact.name}
                                    onChange={(event) =>
                                        setContact(
                                            index,
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                />
                                <input
                                    className="rounded border p-2"
                                    placeholder="E-mail"
                                    value={contact.email ?? ''}
                                    onChange={(event) =>
                                        setContact(
                                            index,
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                />
                                <input
                                    className="rounded border p-2"
                                    placeholder="Telefone"
                                    value={contact.phone ?? ''}
                                    onChange={(event) =>
                                        setContact(
                                            index,
                                            'phone',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        ))}
                    </section>
                    <section className="grid gap-2 rounded-lg border p-4 md:grid-cols-2">
                        <input
                            className="rounded border p-2"
                            placeholder="Limite de crédito"
                            value={form.data.credit_limit}
                            onChange={(event) =>
                                form.setData('credit_limit', event.target.value)
                            }
                        />
                        <input
                            className="rounded border p-2"
                            placeholder="Condições de pagamento"
                            value={form.data.payment_terms}
                            onChange={(event) =>
                                form.setData(
                                    'payment_terms',
                                    event.target.value,
                                )
                            }
                        />
                        <input
                            className="rounded border p-2"
                            placeholder="Classificação"
                            value={form.data.classification}
                            onChange={(event) =>
                                form.setData(
                                    'classification',
                                    event.target.value,
                                )
                            }
                        />
                        <textarea
                            className="rounded border p-2"
                            placeholder="Observações comerciais"
                            value={form.data.commercial_notes}
                            onChange={(event) =>
                                form.setData(
                                    'commercial_notes',
                                    event.target.value,
                                )
                            }
                        />
                    </section>
                    <button
                        className="rounded-md bg-primary px-4 py-2 text-primary-foreground"
                        disabled={form.processing}
                    >
                        Salvar alterações
                    </button>
                    {Object.values(form.errors).map((error) => (
                        <p className="text-sm text-destructive" key={error}>
                            {error}
                        </p>
                    ))}
                </form>
            </main>
        </AppLayout>
    );
}
