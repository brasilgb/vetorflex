import { Head, useForm } from '@inertiajs/react';
import { fiscalBreadcrumb } from '@/components/fiscal/fiscal-nav';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/decimal';
import { csv, index } from '@/routes/fiscal/reports';

export default function FiscalReports({
    rows,
    filters,
    canExport,
}: {
    rows: {
        type: string;
        status: string;
        type_label: string;
        status_label: string;
        count: number;
        total: string;
    }[];
    filters: { from: string; until: string };
    canExport: boolean;
}) {
    const form = useForm({ from: filters.from, until: filters.until });

    return (
        <>
            <Head title="Relatórios fiscais" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Relatórios fiscais"
                        description="Documentos criados no período, por tipo e situação. Não substitui a apuração contábil."
                    />
                    {canExport && (
                        <Button variant="outline" asChild>
                            <a href={csv.url({ query: filters })}>
                                Exportar (CSV)
                            </a>
                        </Button>
                    )}
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), { preserveScroll: true });
                    }}
                >
                    <Input
                        type="date"
                        aria-label="Período: início"
                        className="w-40"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Período: fim"
                        className="w-40"
                        value={form.data.until}
                        onChange={(event) =>
                            form.setData('until', event.target.value)
                        }
                    />
                    <Button variant="outline" disabled={form.processing}>
                        Atualizar
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Tipo</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Documentos</th>
                                <th className="p-3 text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhum documento no período.
                                    </td>
                                </tr>
                            )}
                            {rows.map((row) => (
                                <tr
                                    key={`${row.type}-${row.status}`}
                                    className="border-t"
                                >
                                    <td className="p-3">{row.type_label}</td>
                                    <td className="p-3">{row.status_label}</td>
                                    <td className="p-3 text-right">
                                        {row.count}
                                    </td>
                                    <td className="p-3 text-right">
                                        {formatMoney(row.total)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

FiscalReports.layout = {
    breadcrumbs: [fiscalBreadcrumb, { title: 'Relatórios', href: index() }],
};
