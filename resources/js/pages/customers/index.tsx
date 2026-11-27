import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

type Customer = {
    id: number;
    name: string;
    document: string | null;
    email: string | null;
    is_active: boolean;
};

export default function Customers({
    customers,
    filters,
}: {
    customers: {
        data: Customer[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { search?: string; type?: string; active?: string };
}) {
    const form = useForm({
        type: 'individual',
        name: '',
        document: '',
        email: '',
        phone: '',
        mobile: '',
    });
    const search = useForm({
        search: filters.search ?? '',
        type: filters.type ?? '',
        active: filters.active ?? '',
    });

    return (
        <AppLayout breadcrumbs={[{ title: 'Clientes', href: '/customers' }]}>
            <Head title="Clientes" />
            <main className="space-y-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">Clientes</h1>
                        <p className="text-sm text-muted-foreground">
                            Cadastre e acompanhe os clientes da empresa ativa.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <a
                            className="rounded-md border px-3 py-2 text-sm"
                            href="/customers/export"
                        >
                            Exportar CSV
                        </a>
                        <label className="cursor-pointer rounded-md border px-3 py-2 text-sm">
                            Importar CSV
                            <input
                                className="hidden"
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    if (file)
                                        router.post('/customers/import', {
                                            file,
                                        });
                                }}
                            />
                        </label>
                    </div>
                </div>
                <form
                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/customers');
                    }}
                >
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
                        placeholder="Nome ou razão social"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                    <input
                        className="rounded-md border p-2"
                        placeholder="CPF ou CNPJ"
                        value={form.data.document}
                        onChange={(event) =>
                            form.setData('document', event.target.value)
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
                    <button
                        className="rounded-md bg-primary px-3 py-2 text-primary-foreground disabled:opacity-50"
                        disabled={form.processing}
                    >
                        Cadastrar cliente
                    </button>
                    {Object.values(form.errors).map((error) => (
                        <p
                            className="text-sm text-destructive md:col-span-3"
                            key={error}
                        >
                            {error}
                        </p>
                    ))}
                </form>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        search.get('/customers', { preserveState: true });
                    }}
                >
                    <input
                        className="rounded-md border p-2"
                        placeholder="Pesquisar nome, documento ou e-mail"
                        value={search.data.search}
                        onChange={(event) =>
                            search.setData('search', event.target.value)
                        }
                    />
                    <select
                        className="rounded-md border p-2"
                        value={search.data.type}
                        onChange={(event) =>
                            search.setData('type', event.target.value)
                        }
                    >
                        <option value="">Todos os tipos</option>
                        <option value="individual">Pessoa física</option>
                        <option value="company">Pessoa jurídica</option>
                    </select>
                    <select
                        className="rounded-md border p-2"
                        value={search.data.active}
                        onChange={(event) =>
                            search.setData('active', event.target.value)
                        }
                    >
                        <option value="">Ativos e inativos</option>
                        <option value="1">Ativos</option>
                        <option value="0">Inativos</option>
                    </select>
                    <button className="rounded-md border px-3">Filtrar</button>
                </form>
                <div className="divide-y rounded-lg border">
                    {customers.data.length === 0 ? (
                        <p className="p-6 text-sm text-muted-foreground">
                            Nenhum cliente encontrado.
                        </p>
                    ) : (
                        customers.data.map((customer) => (
                            <Link
                                className="block p-4 hover:bg-muted/50"
                                href={`/customers/${customer.id}`}
                                key={customer.id}
                            >
                                <div className="flex justify-between gap-4">
                                    <span className="font-medium">
                                        {customer.name}
                                    </span>
                                    <span
                                        className={
                                            customer.is_active
                                                ? 'text-sm text-green-700'
                                                : 'text-sm text-muted-foreground'
                                        }
                                    >
                                        {customer.is_active
                                            ? 'Ativo'
                                            : 'Inativo'}
                                    </span>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {customer.document ?? 'Sem documento'} ·{' '}
                                    {customer.email ?? 'Sem e-mail'}
                                </p>
                            </Link>
                        ))
                    )}
                </div>
                <nav className="flex gap-1">
                    {customers.links.map(
                        (link) =>
                            link.url && (
                                <Link
                                    className={
                                        link.active
                                            ? 'rounded bg-muted px-3 py-1 text-sm'
                                            : 'rounded px-3 py-1 text-sm'
                                    }
                                    href={link.url}
                                    key={link.label}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ),
                    )}
                </nav>
            </main>
        </AppLayout>
    );
}
