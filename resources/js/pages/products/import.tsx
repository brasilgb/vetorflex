import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { index as products } from '@/routes/products';
import {
    confirm,
    create,
    errors as errorReport,
    store,
    template,
} from '@/routes/products/imports';

type ImportRow = {
    line: number;
    name: string;
    status: 'new' | 'duplicate' | 'invalid';
    attributes: {
        sku?: string;
        sale_price?: string;
        initial_stock?: string;
    };
    product_id: number | null;
    product_name: string | null;
    errors: string[];
    warnings: string[];
};

type ProductImport = {
    id: number;
    file_name: string;
    status: 'preview' | 'completed';
    rows: ImportRow[];
    report: {
        created: number;
        updated: number;
        skipped: number;
        failed: number;
        errors: { line: number; name: string; messages: string[] }[];
    } | null;
};

const STATUSES = {
    new: { label: 'Novo', variant: 'secondary' },
    duplicate: { label: 'Já existe', variant: 'outline' },
    invalid: { label: 'Com erro', variant: 'destructive' },
} as const;

function UploadForm({
    limits,
}: {
    limits: { rows: number; kilobytes: number };
}) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <form
            className="space-y-4 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(store());
            }}
        >
            <p className="text-sm text-muted-foreground">
                Produtos simples, até {limits.rows} registros e{' '}
                {limits.kilobytes} KB. Nada é gravado antes da sua confirmação.
            </p>
            <div className="flex flex-wrap gap-2">
                <Input
                    type="file"
                    aria-label="Arquivo CSV"
                    accept=".csv,text/csv"
                    className="max-w-sm"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                />
                <Button disabled={form.processing || form.data.file === null}>
                    Gerar prévia
                </Button>
                <Button variant="outline" asChild>
                    <a href={template.url()}>Baixar modelo</a>
                </Button>
            </div>
            <InputError message={form.errors.file} />
        </form>
    );
}

function Preview({ productImport }: { productImport: ProductImport }) {
    const { can } = useTenant();
    const { rows } = productImport;
    const [lines, setLines] = useState<number[]>([]);
    const form = useForm({});
    const creations = rows.filter(({ status }) => status === 'new').length;
    const hasErrors = rows.some(({ errors }) => errors.length > 0);

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                {productImport.file_name}: {creations} novo(s),{' '}
                {rows.filter(({ status }) => status === 'duplicate').length} já
                existente(s) e{' '}
                {rows.filter(({ status }) => status === 'invalid').length} com
                erro. Produtos existentes só são atualizados quando marcados, e
                o estoque deles nunca é alterado.
            </p>
            <div className="max-h-[60vh] overflow-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-muted text-left">
                        <tr>
                            <th className="p-3 font-medium">Linha</th>
                            <th className="p-3 font-medium">Produto</th>
                            <th className="p-3 font-medium">SKU</th>
                            <th className="p-3 font-medium">Venda</th>
                            <th className="p-3 font-medium">Estoque inicial</th>
                            <th className="p-3 font-medium">Situação</th>
                            <th className="p-3 font-medium">Atualizar</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((row) => (
                            <tr key={row.line} className="align-top">
                                <td className="p-3">{row.line}</td>
                                <td className="p-3">
                                    {row.name || '—'}
                                    {row.errors.map((message) => (
                                        <p
                                            key={message}
                                            className="text-destructive"
                                        >
                                            {message}
                                        </p>
                                    ))}
                                    {row.warnings.map((message) => (
                                        <p
                                            key={message}
                                            className="text-muted-foreground"
                                        >
                                            {message}
                                        </p>
                                    ))}
                                </td>
                                <td className="p-3">
                                    {row.attributes.sku ?? '—'}
                                </td>
                                <td className="p-3 whitespace-nowrap">
                                    {row.status === 'invalid'
                                        ? (row.attributes.sale_price ?? '—')
                                        : formatMoney(
                                              row.attributes.sale_price,
                                          )}
                                </td>
                                <td className="p-3">
                                    {row.attributes.initial_stock === undefined
                                        ? '—'
                                        : formatDecimal(
                                              row.attributes.initial_stock,
                                          )}
                                </td>
                                <td className="p-3">
                                    <Badge
                                        variant={STATUSES[row.status].variant}
                                    >
                                        {STATUSES[row.status].label}
                                    </Badge>
                                    {row.product_name !== null && (
                                        <p className="text-muted-foreground">
                                            {row.product_name}
                                        </p>
                                    )}
                                </td>
                                <td className="p-3">
                                    {row.status === 'duplicate' &&
                                        can('products.update') && (
                                            <Checkbox
                                                aria-label={`Atualizar ${row.product_name}`}
                                                checked={lines.includes(
                                                    row.line,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    setLines(
                                                        checked === true
                                                            ? [
                                                                  ...lines,
                                                                  row.line,
                                                              ]
                                                            : lines.filter(
                                                                  (line) =>
                                                                      line !==
                                                                      row.line,
                                                              ),
                                                    )
                                                }
                                            />
                                        )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <InputError message={Object.values<string>(form.errors)[0]} />
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    disabled={form.processing || creations + lines.length === 0}
                    onClick={() => {
                        form.transform(() => ({
                            updates: rows
                                .filter(({ line }) => lines.includes(line))
                                .map(({ line, product_id }) => ({
                                    line,
                                    product_id,
                                })),
                        }));
                        form.submit(confirm(productImport.id));
                    }}
                >
                    Confirmar: criar {creations} e atualizar {lines.length}
                </Button>
                {hasErrors && (
                    <Button variant="outline" asChild>
                        <a href={errorReport.url(productImport.id)}>
                            Baixar erros
                        </a>
                    </Button>
                )}
                <Button variant="ghost" asChild>
                    <Link href={create()}>Descartar</Link>
                </Button>
            </div>
        </div>
    );
}

function Report({ productImport }: { productImport: ProductImport }) {
    const { report } = productImport;

    if (report === null) {
        return null;
    }

    return (
        <div className="space-y-4">
            <p className="text-sm">
                {productImport.file_name}: {report.created} criado(s),{' '}
                {report.updated} atualizado(s), {report.skipped} ignorado(s) e{' '}
                {report.failed} com erro.
            </p>
            {report.errors.length > 0 && (
                <ul className="divide-y rounded-lg border text-sm">
                    {report.errors.map((error) => (
                        <li key={error.line} className="p-3">
                            Linha {error.line} · {error.name || 'sem nome'}:{' '}
                            {error.messages.join(' ')}
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap gap-2">
                {report.errors.length > 0 && (
                    <Button variant="outline" asChild>
                        <a href={errorReport.url(productImport.id)}>
                            Baixar relatório
                        </a>
                    </Button>
                )}
                <Button asChild>
                    <Link href={products()}>Ver produtos</Link>
                </Button>
                <Button variant="ghost" asChild>
                    <Link href={create()}>Nova importação</Link>
                </Button>
            </div>
        </div>
    );
}

export default function ImportProducts({
    limits,
    import: productImport,
}: {
    limits: { rows: number; kilobytes: number };
    import: ProductImport | null;
}) {
    return (
        <>
            <Head title="Importar produtos" />
            <div className="p-4 md:p-6">
                <Heading
                    title="Importar produtos"
                    description="Envie o arquivo, confira a prévia e confirme."
                />
                {productImport === null && <UploadForm limits={limits} />}
                {productImport?.status === 'preview' && (
                    <Preview productImport={productImport} />
                )}
                {productImport?.status === 'completed' && (
                    <Report productImport={productImport} />
                )}
            </div>
        </>
    );
}

ImportProducts.layout = {
    breadcrumbs: [
        { title: 'Produtos', href: products() },
        { title: 'Importar', href: create() },
    ],
};
