import { Head, Link, useForm } from '@inertiajs/react';
import { fiscalBreadcrumb } from '@/components/fiscal/fiscal-nav';
import {
    FiscalEnvironmentBadge,
    FiscalStatusBadge,
} from '@/components/fiscal/fiscal-status';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatMoney } from '@/lib/decimal';
import { create, exportMethod, index, show } from '@/routes/fiscal/documents';
import type { FiscalDocument, Option, Paginated } from '@/types';

type Filters = {
    from: string | null;
    until: string | null;
    status: string | null;
    type: string | null;
    origin: string | null;
    search: string | null;
};

export default function FiscalDocuments({
    documents,
    filters,
    statuses,
    types,
    origins,
    canExport,
    canCreate,
}: {
    documents: Paginated<FiscalDocument>;
    filters: Filters;
    statuses: Option[];
    types: Option[];
    origins: Option[];
    canExport: boolean;
    canCreate: boolean;
}) {
    const { formatDateTime } = useTenant();
    const form = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        type: filters.type ?? '',
        origin: filters.origin ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
    });
    const query = Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );
    const selects: [keyof typeof form.data, string, Option[]][] = [
        ['type', 'Todos os tipos', types],
        ['status', 'Todas as situações', statuses],
        ['origin', 'Todas as origens', origins],
    ];

    return (
        <>
            <Head title="Central de documentos fiscais" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Central de documentos fiscais"
                        description="Rascunhos, documentos emitidos e cancelados. Somente “Autorizado” indica documento válido."
                    />
                    <div className="flex flex-wrap gap-2">
                        {canExport && (
                            <Button variant="outline" asChild>
                                <a href={exportMethod.url({ query })}>
                                    Exportar CSV
                                </a>
                            </Button>
                        )}
                        {canCreate && (
                            <Button asChild>
                                <Link href={create()}>Novo documento</Link>
                            </Button>
                        )}
                    </div>
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) =>
                            Object.fromEntries(
                                Object.entries(data).filter(
                                    ([, value]) => value !== '',
                                ),
                            ),
                        );
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Buscar"
                        placeholder="Número, chave, destinatário ou CPF/CNPJ"
                        className="w-64"
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                    />
                    {selects.map(([field, label, options]) => (
                        <NativeSelect
                            key={field}
                            aria-label={label}
                            className="w-44"
                            value={form.data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.value)
                            }
                        >
                            <option value="">{label}</option>
                            {options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    ))}
                    <Input
                        type="date"
                        aria-label="Criados a partir de"
                        className="w-40"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Criados até"
                        className="w-40"
                        value={form.data.until}
                        onChange={(event) =>
                            form.setData('until', event.target.value)
                        }
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Documento</th>
                                <th className="p-3">Destinatário</th>
                                <th className="p-3">Origem</th>
                                <th className="p-3">Emissão</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            {documents.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhum documento encontrado.
                                    </td>
                                </tr>
                            )}
                            {documents.data.map((document) => (
                                <tr
                                    key={document.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <Link
                                            href={show(document.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {document.type_label}{' '}
                                            {document.number
                                                ? `nº ${document.number}${document.series ? `/${document.series}` : ''}`
                                                : `interno ${document.local_number}`}
                                        </Link>
                                        <span className="mt-1 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                            Interno {document.local_number}
                                            <FiscalEnvironmentBadge
                                                environment={
                                                    document.environment
                                                }
                                            />
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {document.receiver_name ??
                                            'Não identificado'}
                                        {document.receiver_document && (
                                            <span className="block text-xs text-muted-foreground">
                                                {document.receiver_document}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {document.origin_label}
                                        {document.origin_reference && (
                                            <span className="block text-xs text-muted-foreground">
                                                {document.origin_reference}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {document.issued_at
                                            ? formatDateTime(document.issued_at)
                                            : '—'}
                                    </td>
                                    <td className="p-3">
                                        <FiscalStatusBadge
                                            status={document.status}
                                            label={document.status_label}
                                        />
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(document.total)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={documents.links} />
            </div>
        </>
    );
}

FiscalDocuments.layout = {
    breadcrumbs: [fiscalBreadcrumb, { title: 'Documentos', href: index() }],
};
