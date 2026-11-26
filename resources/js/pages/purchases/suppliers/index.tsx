import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { dashboard } from '@/routes/purchases';
import {
    create,
    exportMethod,
    index,
    show,
} from '@/routes/purchases/suppliers';
import { create as importCreate } from '@/routes/purchases/suppliers/imports';
import type { Paginated, SupplierSummary } from '@/types';

export default function Suppliers({
    suppliers,
    filters,
}: {
    suppliers: Paginated<SupplierSummary>;
    filters: Partial<Record<'search' | 'active' | 'type', string | null>>;
}) {
    const { can } = useTenant();
    const form = useForm({
        search: filters.search ?? '',
        active: filters.active ?? '',
        type: filters.type ?? '',
    });
    const { data, setData } = form;
    const query = Object.fromEntries(
        Object.entries(data).filter(([, value]) => value !== ''),
    );

    return (
        <>
            <Head title="Fornecedores" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Fornecedores"
                        description="Cadastro compartilhado de pessoas: um cliente também pode ser fornecedor."
                    />
                    <div className="flex flex-wrap gap-2">
                        {can('purchases.export_suppliers') && (
                            <Button variant="outline" asChild>
                                <a href={exportMethod.url({ query })}>
                                    Exportar CSV
                                </a>
                            </Button>
                        )}
                        {can('purchases.import_suppliers') && (
                            <Button variant="outline" asChild>
                                <Link href={importCreate()}>Importar CSV</Link>
                            </Button>
                        )}
                        {can('purchases.manage_suppliers') && (
                            <Button asChild>
                                <Link href={create()}>Novo fornecedor</Link>
                            </Button>
                        )}
                    </div>
                </div>
                <form
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        className="sm:col-span-2"
                        type="search"
                        aria-label="Pesquisar"
                        placeholder="Nome, CPF/CNPJ, e-mail ou código interno"
                        value={data.search}
                        onChange={(event) =>
                            setData('search', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Situação"
                        value={data.active}
                        onChange={(event) =>
                            setData('active', event.target.value)
                        }
                    >
                        <option value="">Ativos e inativos</option>
                        <option value="1">Ativos</option>
                        <option value="0">Inativos</option>
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Tipo"
                        value={data.type}
                        onChange={(event) =>
                            setData('type', event.target.value)
                        }
                    >
                        <option value="">Todos os tipos</option>
                        <option value="company">Pessoa jurídica</option>
                        <option value="individual">Pessoa física</option>
                    </NativeSelect>
                    <div className="flex gap-2">
                        <Button variant="outline" disabled={form.processing}>
                            Filtrar
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()} preserveState={false}>
                                Limpar
                            </Link>
                        </Button>
                    </div>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Fornecedor</th>
                                <th className="p-3">CPF/CNPJ</th>
                                <th className="p-3">Contato</th>
                                <th className="p-3">Código</th>
                                <th className="p-3">Situação</th>
                            </tr>
                        </thead>
                        <tbody>
                            {suppliers.data.length === 0 && (
                                <tr>
                                    <td
                                        className="p-6 text-center text-muted-foreground"
                                        colSpan={5}
                                    >
                                        Nenhum fornecedor encontrado.
                                    </td>
                                </tr>
                            )}
                            {suppliers.data.map((supplier) => (
                                <tr key={supplier.id} className="border-t">
                                    <td className="p-3">
                                        <Link
                                            className="font-medium underline-offset-4 hover:underline"
                                            href={show(supplier.id)}
                                        >
                                            {supplier.name}
                                        </Link>
                                        {supplier.trade_name && (
                                            <span className="block text-xs text-muted-foreground">
                                                {supplier.trade_name}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {supplier.document ?? '—'}
                                    </td>
                                    <td className="p-3">
                                        {[supplier.phone, supplier.email]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </td>
                                    <td className="p-3">
                                        {supplier.internal_code ?? '—'}
                                    </td>
                                    <td className="p-3">
                                        <span className="inline-flex flex-wrap gap-1">
                                            <Badge
                                                variant={
                                                    supplier.is_active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {supplier.is_active
                                                    ? 'Ativo'
                                                    : 'Inativo'}
                                            </Badge>
                                            {supplier.is_customer && (
                                                <Badge variant="outline">
                                                    Também cliente
                                                </Badge>
                                            )}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={suppliers.links} />
            </div>
        </>
    );
}

Suppliers.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Fornecedores', href: index() },
    ],
};
