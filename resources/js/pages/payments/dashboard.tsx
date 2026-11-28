import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import {
    ChargeStatusBadge,
    EnvironmentBadge,
} from '@/components/payments/charge-status';
import { paymentsBreadcrumb } from '@/components/payments/payments-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import { dashboard, settings } from '@/routes/payments';
import { create, index, show } from '@/routes/payments/charges';
import type { Option, PaymentCharge, PaymentIndicators } from '@/types';

type Filters = {
    from: string;
    until: string;
    provider: string | null;
    origin: string | null;
};

const METHOD_LABELS: Record<string, string> = {
    pix: 'Pix',
    boleto: 'Boleto',
    credit_card: 'Cartão de crédito',
};

function Stat({
    label,
    value,
    hint,
    tone,
}: {
    label: string;
    value: string;
    hint?: string;
    tone?: 'alert';
}) {
    return (
        <div className="rounded-lg border p-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={`text-2xl font-semibold ${tone === 'alert' ? 'text-destructive' : ''}`}
            >
                {value}
            </dd>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function Breakdown({
    title,
    rows,
    labels,
}: {
    title: string;
    rows: PaymentIndicators['by_method'];
    labels: Record<string, string>;
}) {
    return (
        <section aria-label={title} className="space-y-2">
            <h3 className="font-medium">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum recebimento no período.
                </p>
            ) : (
                <ul className="space-y-1">
                    {rows.map((row) => (
                        <li
                            key={row.key}
                            className="flex justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                        >
                            <span>
                                {labels[row.key] ?? row.key}{' '}
                                <span className="text-xs text-muted-foreground">
                                    ({row.count})
                                </span>
                            </span>
                            <span className="font-medium">
                                {formatMoney(row.total)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function PaymentsDashboard({
    indicators,
    filters,
    recent,
    origins,
    providers,
    accounts,
}: {
    indicators: PaymentIndicators;
    filters: Filters;
    recent: PaymentCharge[];
    origins: Option[];
    providers: Option[];
    accounts: {
        name: string;
        environment: string;
        status: string;
        status_label: string;
    }[];
}) {
    const form = useForm({
        from: filters.from,
        until: filters.until,
        provider: filters.provider ?? '',
        origin: filters.origin ?? '',
    });
    const originLabels = Object.fromEntries(
        origins.map((origin) => [origin.value, origin.label]),
    );
    const providerLabels = Object.fromEntries(
        providers.map((provider) => [provider.value, provider.label]),
    );

    return (
        <>
            <Head title="Pagamentos" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Pagamentos"
                        description="Cobranças recebidas na conta da sua empresa no provedor. Somente pagamentos confirmados pelo provedor contam como recebidos."
                    />
                    <Button asChild>
                        <Link href={create()}>Nova cobrança</Link>
                    </Button>
                </div>

                {accounts.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-4 text-sm">
                        Nenhum provedor configurado. As cobranças ficam em
                        rascunho até você{' '}
                        <Link
                            href={settings()}
                            className="underline underline-offset-4"
                        >
                            configurar a conta da empresa no provedor
                        </Link>
                        .
                    </p>
                ) : (
                    <ul className="flex flex-wrap gap-2 text-sm">
                        {accounts.map((account) => (
                            <li
                                key={account.name}
                                className="flex items-center gap-2 rounded-md border px-3 py-1.5"
                            >
                                {account.name} · {account.status_label}
                                <EnvironmentBadge
                                    environment={account.environment}
                                />
                            </li>
                        ))}
                    </ul>
                )}

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
                        aria-label="Provedor"
                        className="w-40"
                        value={form.data.provider}
                        onChange={(event) =>
                            form.setData('provider', event.target.value)
                        }
                    >
                        <option value="">Todos os provedores</option>
                        {providers.map((provider) => (
                            <option key={provider.value} value={provider.value}>
                                {provider.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Origem"
                        className="w-40"
                        value={form.data.origin}
                        onChange={(event) =>
                            form.setData('origin', event.target.value)
                        }
                    >
                        <option value="">Todas as origens</option>
                        {origins.map((origin) => (
                            <option key={origin.value} value={origin.value}>
                                {origin.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button variant="outline" disabled={form.processing}>
                        Atualizar
                    </Button>
                </form>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label="Total recebido"
                        value={formatMoney(indicators.received)}
                        hint={
                            indicators.refunded !== '0.00'
                                ? `Bruto ${formatMoney(indicators.gross)} · devolvido ${formatMoney(indicators.refunded)}`
                                : undefined
                        }
                    />
                    <Stat
                        label="Pendente"
                        value={formatMoney(indicators.pending)}
                    />
                    <Stat
                        label="Vencido"
                        value={formatMoney(indicators.overdue)}
                        tone={
                            indicators.overdue !== '0.00' ? 'alert' : undefined
                        }
                    />
                    <Stat
                        label="Taxa de recebimento"
                        value={
                            indicators.collection_rate === null
                                ? '—'
                                : `${formatDecimal(indicators.collection_rate)}%`
                        }
                        hint={`${indicators.paid} paga(s) de ${indicators.issued} emitida(s)`}
                    />
                    {indicators.fees !== null && (
                        <Stat
                            label="Taxas informadas"
                            value={formatMoney(indicators.fees)}
                        />
                    )}
                    {indicators.net !== null && (
                        <Stat
                            label="Valor líquido"
                            value={formatMoney(indicators.net)}
                        />
                    )}
                    <Stat
                        label="Falhas de processamento"
                        value={String(indicators.failures)}
                        tone={indicators.failures > 0 ? 'alert' : undefined}
                    />
                </dl>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Breakdown
                        title="Por forma de pagamento"
                        rows={indicators.by_method}
                        labels={METHOD_LABELS}
                    />
                    <Breakdown
                        title="Por provedor"
                        rows={indicators.by_provider}
                        labels={providerLabels}
                    />
                    <Breakdown
                        title="Por origem"
                        rows={indicators.by_origin}
                        labels={originLabels}
                    />
                </div>

                <section aria-label="Últimas cobranças" className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h3 className="font-medium">Últimas cobranças</h3>
                        <Link
                            href={index()}
                            className="text-sm underline-offset-4 hover:underline"
                        >
                            Ver todas
                        </Link>
                    </div>
                    <ul className="divide-y rounded-lg border">
                        {recent.length === 0 && (
                            <li className="p-4 text-sm text-muted-foreground">
                                Nenhuma cobrança criada.
                            </li>
                        )}
                        {recent.map((charge) => (
                            <li
                                key={charge.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                            >
                                <Link
                                    href={show(charge.id)}
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    #{charge.number} · {charge.description}
                                </Link>
                                <span className="flex items-center gap-2">
                                    {formatMoney(charge.amount)}
                                    <ChargeStatusBadge
                                        status={charge.status}
                                        label={charge.status_label}
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

PaymentsDashboard.layout = { breadcrumbs: [paymentsBreadcrumb] };
