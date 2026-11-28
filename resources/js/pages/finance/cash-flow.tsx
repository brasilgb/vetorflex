import { Head, useForm } from '@inertiajs/react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { cashFlow } from '@/routes/finance';
import type { CashFlowData, FinanceOption } from '@/types';

type Filters = {
    from: string;
    until: string;
    granularity: string;
    account: number | null;
};

const negative = (value: string): string =>
    value.startsWith('-') ? 'text-destructive' : '';

export default function FinanceCashFlow({
    flow,
    filters,
    accounts,
    today,
}: {
    flow: CashFlowData;
    filters: Filters;
    accounts: FinanceOption[];
    today: string;
}) {
    const form = useForm({
        from: filters.from,
        until: filters.until,
        granularity: filters.granularity,
        account: filters.account === null ? '' : String(filters.account),
    });

    return (
        <>
            <Head title="Fluxo de caixa" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Fluxo de caixa"
                    description={`Realizado: baixas e movimentações efetivas. Previsto: saldo em aberto dos títulos a partir de hoje (${formatDate(today)}); vencidos entram hoje.`}
                />
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
                        form.get(cashFlow.url(), { preserveScroll: true });
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
                    <NativeSelect
                        aria-label="Conta"
                        className="w-48"
                        value={form.data.account}
                        onChange={(event) =>
                            form.setData('account', event.target.value)
                        }
                    >
                        <option value="">Consolidado</option>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button variant="outline" disabled={form.processing}>
                        Aplicar
                    </Button>
                </form>
                {filters.account !== null && (
                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                        Por conta, o fluxo mostra só o realizado (inclusive
                        transferências): títulos em aberto ainda não têm conta
                        definida.
                    </p>
                )}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            Saldo inicial
                        </p>
                        <p
                            className={`mt-1 text-2xl font-semibold ${negative(flow.opening)}`}
                        >
                            {formatMoney(flow.opening)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            Entradas
                        </p>
                        <p className="mt-1 text-2xl font-semibold">
                            {formatMoney(flow.totals.realized_in)}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Previstas: {formatMoney(flow.totals.forecast_in)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">Saídas</p>
                        <p className="mt-1 text-2xl font-semibold">
                            {formatMoney(flow.totals.realized_out)}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Previstas: {formatMoney(flow.totals.forecast_out)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            Saldo final projetado
                        </p>
                        <p
                            className={`mt-1 text-2xl font-semibold ${negative(flow.closing)}`}
                        >
                            {formatMoney(flow.closing)}
                        </p>
                    </div>
                </div>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Período</th>
                                <th className="p-3 text-right">
                                    Entradas realizadas
                                </th>
                                <th className="p-3 text-right">
                                    Saídas realizadas
                                </th>
                                <th className="p-3 text-right">
                                    Entradas previstas
                                </th>
                                <th className="p-3 text-right">
                                    Saídas previstas
                                </th>
                                <th className="p-3 text-right">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            {flow.rows.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nada realizado ou previsto no período.
                                    </td>
                                </tr>
                            )}
                            {flow.rows.map((row) => (
                                <tr key={row.period} className="border-t">
                                    <td className="p-3 whitespace-nowrap">
                                        {row.label}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(row.realized_in)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(row.realized_out)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap text-muted-foreground">
                                        {formatMoney(row.forecast_in)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap text-muted-foreground">
                                        {formatMoney(row.forecast_out)}
                                    </td>
                                    <td
                                        className={`p-3 text-right font-medium whitespace-nowrap ${negative(row.balance)}`}
                                    >
                                        {formatMoney(row.balance)}
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

FinanceCashFlow.layout = {
    breadcrumbs: [
        financeBreadcrumb,
        { title: 'Fluxo de caixa', href: cashFlow() },
    ],
};
