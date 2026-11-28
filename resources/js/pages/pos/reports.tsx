import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { posBreadcrumb } from '@/components/pos/pos-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/decimal';
import { csv, index } from '@/routes/pos/reports';
import type { NamedRecord, Option } from '@/types';

type Filters = {
    type: string;
    from: string;
    until: string;
    terminal: number | null;
    operator: number | null;
};

type Report = {
    type: string;
    title: string;
    columns: { key: string; label: string; align?: 'right' }[];
    rows: Record<string, string | null>[];
};

type Summary = {
    sales_count: number;
    total: string;
    average_ticket: string;
    received: string;
    term: string;
    cancelled_count: number;
    refunded: string;
    divergence: string;
};

/** Colunas numéricas que são contagens, não valores monetários. */
const COUNT_COLUMNS = ['count', 'number', 'quantity'];

function display(value: string | null | undefined, isMoney: boolean): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return isMoney ? formatMoney(value) : value;
}

export default function PosReports({
    types,
    summary,
    report,
    filters,
    terminals,
    operators,
    canExport,
}: {
    types: Option[];
    summary: Summary;
    report: Report;
    filters: Filters;
    terminals: NamedRecord[];
    operators: NamedRecord[];
    canExport: boolean;
}) {
    const form = useForm({
        type: filters.type,
        from: filters.from,
        until: filters.until,
        terminal: filters.terminal === null ? '' : String(filters.terminal),
        operator: filters.operator === null ? '' : String(filters.operator),
    });
    const query = Object.fromEntries(
        Object.entries({
            ...filters,
            terminal: filters.terminal ?? '',
            operator: filters.operator ?? '',
        }).filter(([, value]) => value !== ''),
    );
    const cards: [string, string][] = [
        ['Vendas concluídas', String(summary.sales_count)],
        ['Total vendido', formatMoney(summary.total)],
        ['Ticket médio', formatMoney(summary.average_ticket)],
        ['Recebido (informado)', formatMoney(summary.received)],
        ['A prazo', formatMoney(summary.term)],
        ['Cancelamentos', String(summary.cancelled_count)],
        ['Restituído em devoluções', formatMoney(summary.refunded)],
        ['Divergência de caixa', formatMoney(summary.divergence)],
    ];

    return (
        <>
            <Head title="Relatórios do PDV" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Relatórios do PDV"
                        description="Indicadores do caixa com dados reais, por período, terminal e operador. Não exibem custo nem margem."
                    />
                    {canExport && (
                        <Button variant="outline" asChild>
                            <a href={csv.url({ query })}>Exportar CSV</a>
                        </Button>
                    )}
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
                        form.get(index.url(), { preserveScroll: true });
                    }}
                >
                    <NativeSelect
                        aria-label="Relatório"
                        className="w-56"
                        value={form.data.type}
                        onChange={(event) =>
                            form.setData('type', event.target.value)
                        }
                    >
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </NativeSelect>
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
                    <NativeSelect
                        aria-label="Terminal"
                        className="w-44"
                        value={form.data.terminal}
                        onChange={(event) =>
                            form.setData('terminal', event.target.value)
                        }
                    >
                        <option value="">Todos os terminais</option>
                        {terminals.map((terminal) => (
                            <option key={terminal.id} value={terminal.id}>
                                {terminal.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Operador"
                        className="w-44"
                        value={form.data.operator}
                        onChange={(event) =>
                            form.setData('operator', event.target.value)
                        }
                    >
                        <option value="">Todos os operadores</option>
                        {operators.map((operator) => (
                            <option key={operator.id} value={operator.id}>
                                {operator.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button variant="outline" disabled={form.processing}>
                        Gerar
                    </Button>
                </form>
                {(form.errors.until || form.errors.from) && (
                    <p className="text-sm text-destructive">
                        {form.errors.until ?? form.errors.from}
                    </p>
                )}
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-lg font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>
                <section className="space-y-3" aria-label={report.title}>
                    <h3 className="font-medium">{report.title}</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    {report.columns.map((column) => (
                                        <th
                                            key={column.key}
                                            className={`p-3 ${column.align === 'right' ? 'text-right' : ''}`}
                                        >
                                            {column.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {report.rows.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={report.columns.length}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            Nenhum registro para os filtros
                                            escolhidos.
                                        </td>
                                    </tr>
                                )}
                                {report.rows.map((row, rowIndex) => (
                                    <tr key={rowIndex} className="border-t">
                                        {report.columns.map((column) => (
                                            <td
                                                key={column.key}
                                                className={`p-3 ${column.align === 'right' ? 'text-right whitespace-nowrap' : ''}`}
                                            >
                                                {display(
                                                    row[column.key],
                                                    column.align === 'right' &&
                                                        !COUNT_COLUMNS.includes(
                                                            column.key,
                                                        ),
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

PosReports.layout = {
    breadcrumbs: [posBreadcrumb, { title: 'Relatórios', href: index() }],
};
