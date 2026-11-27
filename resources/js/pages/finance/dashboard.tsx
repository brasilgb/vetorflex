import { Head, Link, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { financeBreadcrumb } from '@/components/finance/finance-nav';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { cashFlow, dashboard } from '@/routes/finance';
import { index as titles, show } from '@/routes/finance/titles';
import type { FinanceDashboardData } from '@/types';

function Tile({
    label,
    value,
    hint,
    isNegative = false,
}: {
    label: string;
    value: ReactNode;
    hint?: string;
    isNegative?: boolean;
}) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={`mt-1 text-2xl font-semibold whitespace-nowrap ${isNegative ? 'text-destructive' : ''}`}
            >
                {value}
            </p>
            {hint && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

const isNegative = (value: string): boolean => value.startsWith('-');

export default function FinanceDashboard({
    dashboard: data,
    today,
}: {
    dashboard: FinanceDashboardData;
    today: string;
}) {
    const { can } = useTenant();
    const form = useForm({
        from: data.period.from,
        until: data.period.until,
        account: data.account_id === null ? '' : String(data.account_id),
    });
    const highest = Math.max(
        1,
        ...data.monthly.flatMap((month) => [
            Number(month.income),
            Number(month.expense),
        ]),
    );
    const highestCategory = Math.max(
        1,
        ...data.expenses_by_category.map((row) => Number(row.total)),
    );

    return (
        <>
            <Head title="Financeiro" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Visão geral financeira"
                    description="Previsto são títulos em aberto; realizado são baixas efetivas. Venda só vira dinheiro recebido depois da baixa."
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((values) =>
                            Object.fromEntries(
                                Object.entries(values).filter(
                                    ([, value]) => value !== '',
                                ),
                            ),
                        );
                        form.get(dashboard.url(), { preserveScroll: true });
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
                        aria-label="Conta financeira"
                        className="w-52"
                        value={form.data.account}
                        onChange={(event) =>
                            form.setData('account', event.target.value)
                        }
                    >
                        <option value="">Todas as contas</option>
                        {data.accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button variant="outline" disabled={form.processing}>
                        Aplicar
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        {formatDate(data.period.from)} a{' '}
                        {formatDate(data.period.until)}
                    </p>
                </form>
                {data.accounts.length === 0 && (
                    <p className="rounded-lg border border-dashed p-4 text-sm">
                        Comece cadastrando o caixa ou a conta bancária da
                        empresa em <strong>Contas financeiras</strong>. Depois,
                        lance as contas a receber e a pagar.
                    </p>
                )}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Tile
                        label="Saldo disponível"
                        value={formatMoney(data.available_balance)}
                        hint="Soma das contas ativas, hoje"
                        isNegative={isNegative(data.available_balance)}
                    />
                    <Tile
                        label="Receitas recebidas"
                        value={formatMoney(data.received)}
                        hint="Realizado no período"
                    />
                    <Tile
                        label="Despesas pagas"
                        value={formatMoney(data.paid)}
                        hint="Realizado no período"
                    />
                    <Tile
                        label="Resultado do período"
                        value={formatMoney(data.result)}
                        hint="Recebido menos pago, sem transferências"
                        isNegative={isNegative(data.result)}
                    />
                    <Tile
                        label="Total a receber"
                        value={formatMoney(data.to_receive)}
                        hint={`Previsto · vence em 7 dias: ${formatMoney(data.due_soon_receivable)}`}
                    />
                    <Tile
                        label="Total a pagar"
                        value={formatMoney(data.to_pay)}
                        hint={`Previsto · vence em 7 dias: ${formatMoney(data.due_soon_payable)}`}
                    />
                    <Tile
                        label="A receber vencido"
                        value={formatMoney(data.overdue_receivable)}
                        isNegative={data.overdue_receivable !== '0.00'}
                    />
                    <Tile
                        label="A pagar vencido"
                        value={formatMoney(data.overdue_payable)}
                        isNegative={data.overdue_payable !== '0.00'}
                    />
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <section
                        className="space-y-3 rounded-lg border p-4"
                        aria-label="Próximos vencimentos"
                    >
                        <h3 className="font-medium">Próximos vencimentos</h3>
                        {data.next_due.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum título em aberto.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {data.next_due.map((title) => (
                                    <li
                                        key={title.id}
                                        className="flex items-start justify-between gap-3 py-2"
                                    >
                                        <span className="min-w-0">
                                            <Link
                                                className="font-medium underline-offset-4 hover:underline"
                                                href={show(title.id)}
                                            >
                                                {title.kind === 'receivable'
                                                    ? 'Receber'
                                                    : 'Pagar'}{' '}
                                                #{title.number} —{' '}
                                                {title.description}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {title.party_name ??
                                                    'Sem pessoa'}{' '}
                                                ·{' '}
                                                <span
                                                    className={
                                                        title.is_overdue
                                                            ? 'text-destructive'
                                                            : ''
                                                    }
                                                >
                                                    {title.is_overdue
                                                        ? 'Vencido em '
                                                        : 'Vence em '}
                                                    {formatDate(title.due_date)}
                                                </span>
                                            </span>
                                        </span>
                                        <span className="whitespace-nowrap">
                                            {formatMoney(title.open)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="flex gap-3 text-sm">
                            {can('finance.view_receivables') && (
                                <Link
                                    className="underline"
                                    href={titles('receivables')}
                                >
                                    Contas a receber
                                </Link>
                            )}
                            {can('finance.view_payables') && (
                                <Link
                                    className="underline"
                                    href={titles('payables')}
                                >
                                    Contas a pagar
                                </Link>
                            )}
                        </div>
                    </section>
                    <section
                        className="space-y-3 rounded-lg border p-4"
                        aria-label="Saldo por conta"
                    >
                        <h3 className="font-medium">Saldo por conta</h3>
                        {data.accounts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhuma conta cadastrada.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {data.accounts.map((account) => (
                                    <li
                                        key={account.id}
                                        className="flex justify-between gap-3 py-2"
                                    >
                                        <span>
                                            {account.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {account.type}
                                                {!account.is_active &&
                                                    ' · inativa'}
                                            </span>
                                        </span>
                                        <span
                                            className={`whitespace-nowrap ${isNegative(account.balance) ? 'text-destructive' : ''}`}
                                        >
                                            {formatMoney(account.balance)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <section
                        className="space-y-3 rounded-lg border p-4"
                        aria-label="Receitas e despesas realizadas"
                    >
                        <h3 className="font-medium">
                            Receitas e despesas realizadas (12 meses)
                        </h3>
                        <div className="flex gap-4 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1">
                                <span className="size-2.5 rounded-sm bg-primary" />
                                Receitas
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="size-2.5 rounded-sm bg-muted-foreground/60" />
                                Despesas
                            </span>
                        </div>
                        <ul className="space-y-2 text-sm">
                            {data.monthly.map((month) => (
                                <li
                                    key={month.month}
                                    className="grid grid-cols-[4rem_1fr_7.5rem] items-center gap-2"
                                >
                                    <span className="text-muted-foreground">
                                        {month.month
                                            .split('-')
                                            .reverse()
                                            .join('/')}
                                    </span>
                                    <span className="space-y-0.5">
                                        <span className="block h-2 rounded bg-muted">
                                            <span
                                                className="block h-2 rounded bg-primary"
                                                style={{
                                                    width: `${(Number(month.income) / highest) * 100}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="block h-2 rounded bg-muted">
                                            <span
                                                className="block h-2 rounded bg-muted-foreground/60"
                                                style={{
                                                    width: `${(Number(month.expense) / highest) * 100}%`,
                                                }}
                                            />
                                        </span>
                                    </span>
                                    <span className="text-right text-xs whitespace-nowrap">
                                        {formatMoney(month.income)}
                                        <span className="block text-muted-foreground">
                                            {formatMoney(month.expense)}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                    <section
                        className="space-y-3 rounded-lg border p-4"
                        aria-label="Despesas por categoria"
                    >
                        <h3 className="font-medium">
                            Despesas pagas por categoria
                        </h3>
                        {data.expenses_by_category.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhuma despesa paga no período.
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {data.expenses_by_category.map((row) => (
                                    <li
                                        key={row.category}
                                        className="space-y-1"
                                    >
                                        <span className="flex justify-between gap-3">
                                            <span>{row.category}</span>
                                            <span className="whitespace-nowrap">
                                                {formatMoney(row.total)}
                                            </span>
                                        </span>
                                        <span className="block h-2 rounded bg-muted">
                                            <span
                                                className="block h-2 rounded bg-primary"
                                                style={{
                                                    width: `${(Number(row.total) / highestCategory) * 100}%`,
                                                }}
                                            />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
                <section
                    className="space-y-3 rounded-lg border p-4"
                    aria-label="Fluxo de caixa projetado"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-medium">
                            Fluxo de caixa projetado (30 dias, a partir de{' '}
                            {formatDate(today)})
                        </h3>
                        {can('finance.view_reports') && (
                            <Link
                                className="text-sm underline"
                                href={cashFlow()}
                            >
                                Ver fluxo de caixa
                            </Link>
                        )}
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="py-2 pr-3">Período</th>
                                    <th className="py-2 pr-3 text-right">
                                        Entradas previstas
                                    </th>
                                    <th className="py-2 pr-3 text-right">
                                        Saídas previstas
                                    </th>
                                    <th className="py-2 text-right">
                                        Saldo projetado
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.projection.rows.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="py-3 text-muted-foreground"
                                        >
                                            Sem movimentos previstos. Saldo
                                            atual:{' '}
                                            {formatMoney(
                                                data.projection.opening,
                                            )}
                                            .
                                        </td>
                                    </tr>
                                )}
                                {data.projection.rows.map((row) => (
                                    <tr key={row.period} className="border-t">
                                        <td className="py-2 pr-3">
                                            {row.label}
                                        </td>
                                        <td className="py-2 pr-3 text-right whitespace-nowrap">
                                            {formatMoney(row.forecast_in)}
                                        </td>
                                        <td className="py-2 pr-3 text-right whitespace-nowrap">
                                            {formatMoney(row.forecast_out)}
                                        </td>
                                        <td
                                            className={`py-2 text-right whitespace-nowrap ${isNegative(row.balance) ? 'text-destructive' : ''}`}
                                        >
                                            {formatMoney(row.balance)}
                                        </td>
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

FinanceDashboard.layout = { breadcrumbs: [financeBreadcrumb] };
