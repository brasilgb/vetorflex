import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { csv, index } from '@/routes/inventory/reports';
import type { Option, StockReportTable, StockWarehouseOption } from '@/types';

type Filters = {
    type: string;
    from: string;
    until: string;
    warehouse: number | null;
    days: number;
};

/** Colunas com valores monetários; as demais colunas numéricas são quantidades já formatadas pelo servidor. */
const MONEY_COLUMNS = ['cost', 'value'];
const USES_PERIOD = ['movements'];
const USES_WAREHOUSE = ['balances', 'low_stock', 'above_max', 'movements'];

function display(value: string | null | undefined, key: string): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (MONEY_COLUMNS.includes(key)) {
        return formatMoney(value);
    }

    return /^-?\d+(\.\d+)?$/.test(value) ? formatDecimal(value) : value;
}

export default function StockReports({
    types,
    report,
    filters,
    warehouses,
}: {
    types: Option[];
    report: StockReportTable;
    filters: Filters;
    warehouses: StockWarehouseOption[];
}) {
    const form = useForm({
        type: filters.type,
        from: filters.from,
        until: filters.until,
        warehouse: filters.warehouse === null ? '' : String(filters.warehouse),
        days: String(filters.days),
    });
    const query = Object.fromEntries(
        Object.entries({
            ...filters,
            warehouse: filters.warehouse ?? '',
        }).filter(([, value]) => value !== ''),
    );

    return (
        <>
            <Head title="Relatórios de estoque" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Relatórios de estoque"
                        description="Posição, alertas, movimentação e valor estimado, com dados reais."
                    />
                    <Button variant="outline" asChild>
                        <a href={csv.url({ query })}>Exportar CSV</a>
                    </Button>
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
                        className="w-60"
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
                    {USES_PERIOD.includes(form.data.type) && (
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
                    {USES_WAREHOUSE.includes(form.data.type) && (
                        <NativeSelect
                            aria-label="Depósito"
                            className="w-44"
                            value={form.data.warehouse}
                            onChange={(event) =>
                                form.setData('warehouse', event.target.value)
                            }
                        >
                            <option value="">Todos os depósitos</option>
                            {warehouses.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.name}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                    {form.data.type === 'no_movement' && (
                        <label className="flex items-center gap-2 text-sm">
                            Sem movimentação há
                            <Input
                                type="number"
                                min={1}
                                max={3650}
                                className="w-24"
                                value={form.data.days}
                                onChange={(event) =>
                                    form.setData('days', event.target.value)
                                }
                            />
                            dias
                        </label>
                    )}
                    <Button variant="outline" disabled={form.processing}>
                        Gerar
                    </Button>
                </form>
                {(form.errors.until || form.errors.days) && (
                    <p className="text-sm text-destructive">
                        {form.errors.until ?? form.errors.days}
                    </p>
                )}
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
                                                    column.key,
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

StockReports.layout = {
    breadcrumbs: [inventoryBreadcrumb, { title: 'Relatórios', href: index() }],
};
