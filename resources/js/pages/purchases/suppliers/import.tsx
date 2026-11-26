import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes/purchases';
import { index } from '@/routes/purchases/suppliers';
import {
    confirm,
    create,
    errors as errorReport,
    store,
    template,
} from '@/routes/purchases/suppliers/imports';

type Row = {
    line: number;
    name: string;
    status: 'new' | 'link' | 'existing' | 'invalid';
    party_name: string | null;
    document: string | null;
    errors: string[];
};

type SupplierImport = {
    id: number;
    file_name: string;
    status: 'preview' | 'completed';
    rows: Row[];
    report: {
        created: number;
        linked: number;
        skipped: number;
        failed: number;
        errors: { line: number; name: string; messages: string[] }[];
    } | null;
};

const STATUSES = {
    new: { label: 'Novo', variant: 'secondary' },
    link: { label: 'Pessoa existente', variant: 'outline' },
    existing: { label: 'Já é fornecedor', variant: 'outline' },
    invalid: { label: 'Com erro', variant: 'destructive' },
} as const;

export default function SupplierImportPage({
    limits,
    import: current,
}: {
    limits: { rows: number; kilobytes: number };
    import: SupplierImport | null;
}) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const [linkExisting, setLinkExisting] = useState(false);
    const [isConfirming, setIsConfirming] = useState(false);

    return (
        <>
            <Head title="Importar fornecedores" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Importar fornecedores"
                    description={`CSV separado por ponto e vírgula, até ${limits.rows} registros e ${limits.kilobytes} KB. Nada é gravado antes da confirmação.`}
                />
                {current === null && (
                    <form
                        className="space-y-3 rounded-lg border p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            upload.post(store.url(), { forceFormData: true });
                        }}
                    >
                        <Input
                            type="file"
                            accept=".csv,text/csv"
                            aria-label="Arquivo CSV"
                            onChange={(event) =>
                                upload.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <InputError message={upload.errors.file} />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={
                                    upload.processing ||
                                    upload.data.file === null
                                }
                            >
                                Pré-visualizar
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={template.url()}>Baixar modelo</a>
                            </Button>
                        </div>
                    </form>
                )}
                {current !== null && current.status === 'preview' && (
                    <section className="space-y-4">
                        <p className="text-sm">
                            {current.file_name}: {current.rows.length} linha(s).
                        </p>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2">Linha</th>
                                        <th className="p-2">Nome</th>
                                        <th className="p-2">Documento</th>
                                        <th className="p-2">Resultado</th>
                                        <th className="p-2">Detalhes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {current.rows.map((row) => (
                                        <tr key={row.line} className="border-t">
                                            <td className="p-2">{row.line}</td>
                                            <td className="p-2">
                                                {row.name || '—'}
                                            </td>
                                            <td className="p-2">
                                                {row.document ?? '—'}
                                            </td>
                                            <td className="p-2">
                                                <Badge
                                                    variant={
                                                        STATUSES[row.status]
                                                            .variant
                                                    }
                                                >
                                                    {STATUSES[row.status].label}
                                                </Badge>
                                            </td>
                                            <td className="p-2">
                                                {row.errors.join(' ') ||
                                                    (row.party_name
                                                        ? `Cadastrada como ${row.party_name}`
                                                        : '')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {current.rows.some((row) => row.status === 'link') && (
                            <CheckboxField
                                id="link-existing"
                                label="Adicionar o papel fornecedor às pessoas já cadastradas (os dados delas não serão alterados)"
                                checked={linkExisting}
                                onChange={setLinkExisting}
                            />
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={isConfirming}
                                onClick={() =>
                                    router.post(
                                        confirm.url(current.id),
                                        { link_existing: linkExisting },
                                        {
                                            onStart: () =>
                                                setIsConfirming(true),
                                            onFinish: () =>
                                                setIsConfirming(false),
                                        },
                                    )
                                }
                            >
                                Confirmar importação
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={create()}>
                                    Enviar outro arquivo
                                </Link>
                            </Button>
                        </div>
                    </section>
                )}
                {current !== null && current.report !== null && (
                    <section className="space-y-3 rounded-lg border p-4 text-sm">
                        <p>
                            Criados: {current.report.created} · Vinculados:{' '}
                            {current.report.linked} · Ignorados:{' '}
                            {current.report.skipped} · Com erro:{' '}
                            {current.report.failed}
                        </p>
                        {current.report.errors.length > 0 && (
                            <Button variant="outline" asChild>
                                <a href={errorReport.url(current.id)}>
                                    Baixar relatório de ocorrências
                                </a>
                            </Button>
                        )}
                        <Button variant="link" asChild>
                            <Link href={index()}>Ir para fornecedores</Link>
                        </Button>
                    </section>
                )}
            </div>
        </>
    );
}

SupplierImportPage.layout = {
    breadcrumbs: [
        { title: 'Compras', href: dashboard() },
        { title: 'Fornecedores', href: index() },
        { title: 'Importar', href: '#' },
    ],
};
