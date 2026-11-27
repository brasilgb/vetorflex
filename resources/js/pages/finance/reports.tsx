import { Head, useForm } from '@inertiajs/react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { csv, index, pdf } from '@/routes/finance/reports';
import type { FinanceOption, FinanceReportTable, Option } from '@/types';

type Filters = {
    type: string;
    from: string;
    until: string;
    regime: string;
    account_id: number | null;
    granularity: string;
};

/** Relatórios que usam cada filtro além do período. */
const USES_REGIME = ['income_expense'];
const USES_ACCOUNT = ['statement', 'cash_flow'];
const USES_PERIOD_ONLY_TODAY = ['overdue', 'delinquency'];
/** Colunas numéricas que não são valores monetários. */
const COUNT_COLUMNS = ['titles', 'days'];

function display(value: string | null | undefined, isMoney: boolean): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (isMoney) {
        return formatMoney(value);
    }

    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value) : value;
}

export default function FinanceReports({
    types,
    report,
    filters,
    accounts,
    totalLabels,
    canExport,
}: {
    types: Option[];
    report: FinanceReportTable;
    filters: Filters;
    accounts: FinanceOption[];
    totalLabels: Record<string, string>;
    canExport: boolean;
}) {
    const form = useForm({
        type: filters.type,
        from: filters.from,
        until: filters.until,
        regime: filters.regime,
        account_id:
            filters.account_id === null ? '' : String(filters.account_id),
        granularity: filters.granularity,
    });
    const query = Object.fromEntries(
        Object.entries({
            ...filters,
            account_id: filters.account_id ?? '',
        }).filter(([, value]) => value !== ''),
    );

    return (
        <>
            <Head title="Relatórios financeiros" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Relatórios financeiros"
                        description="Relatórios gerenciais com dados reais. Não substituem as demonstrações contábeis oficiais."
                    />
                    {canExport && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <a href={csv.url({ query })}>Exportar CSV</a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a
                                    href={pdf.url({ query })}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Gerar PDF
                                </a>
                            </Button>
                        </div>
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
                        className="w-64"
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
                    {!USES_PERIOD_ONLY_TODAY.includes(form.data.type) && (
                        <>
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
                        </>
                    )}
                    {USES_REGIME.includes(form.data.type) && (
                        <NativeSelect
                            aria-label="Regime"
                            className="w-44"
                            value={form.data.regime}
                            onChange={(event) =>
                                form.setData('regime', event.target.value)
                            }
                        >
                            <option value="cash">Regime de caixa</option>
                            <option value="competence">
                                Regime de competência
                            </option>
                        </NativeSelect>
                    )}
                    {USES_ACCOUNT.includes(form.data.type) && (
                        <NativeSelect
                            aria-label="Conta"
                            className="w-48"
                            value={form.data.account_id}
                            onChange={(event) =>
                                form.setData('account_id', event.target.value)
                            }
                        >
                            <option value="">Todas as contas</option>
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                    {form.data.type === 'cash_flow' && (
                        <NativeSelect
                            aria-label="Agrupamento"
                            className="w-36"
                            value={form.data.granularity}
                            onChange={(event) =>
                                form.setData('granularity', event.target.value)
                            }
                        >
                            <option value="day">Diário</option>
                            <option value="week">Semanal</option>
                            <option value="month">Mensal</option>
                        </NativeSelect>
                    )}
                    <Button variant="outline" disabled={form.processing}>
                        Gerar
                    </Button>
                </form>
                <section className="space-y-3" aria-label={report.title}>
                    <div>
                        <h3 className="font-medium">{report.title}</h3>
                        <p className="text-sm text-muted-foreground">
                            {report.subtitle}
                        </p>
                    </div>
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
                    {Object.keys(report.totals).length > 0 && (
                        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {Object.entries(report.totals).map(
                                ([key, value]) => (
                                    <div
                                        key={key}
                                        className="rounded-lg border p-3"
                                    >
                                        <dt className="text-sm text-muted-foreground">
                                            {totalLabels[key] ?? key}
                                        </dt>
                                        <dd className="text-lg font-semibold">
                                            {formatMoney(value)}
                                        </dd>
                                    </div>
                                ),
                            )}
                        </dl>
                    )}
                </section>
            </div>
        </>
    );
}

FinanceReports.layout = {
    breadcrumbs: [financeBreadcrumb, { title: 'Relatórios', href: index() }],
};
