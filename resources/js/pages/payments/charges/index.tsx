import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import {
    ChargeStatusBadge,
    EnvironmentBadge,
} from '@/components/payments/charge-status';
import { paymentsBreadcrumb } from '@/components/payments/payments-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/date';
import { formatMoney } from '@/lib/decimal';
import { create, exportMethod, index, show } from '@/routes/payments/charges';
import type { Option, Paginated, PaymentCharge } from '@/types';

type Filters = {
    from: string | null;
    until: string | null;
    status: string | null;
    provider: string | null;
    origin: string | null;
    search: string | null;
};

export default function PaymentCharges({
    charges,
    filters,
    statuses,
    origins,
    providers,
    canExport,
    canCreate,
}: {
    charges: Paginated<PaymentCharge>;
    filters: Filters;
    statuses: Option[];
    origins: Option[];
    providers: Option[];
    canExport: boolean;
    canCreate: boolean;
}) {
    const form = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        origin: filters.origin ?? '',
        provider: filters.provider ?? '',
        from: filters.from ?? '',
        until: filters.until ?? '',
    });
    const query = Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );

    return (
        <>
            <Head title="Cobranças" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Cobranças"
                        description="Todas as cobranças da empresa, com a situação confirmada pelo provedor."
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
                                <Link href={create()}>Nova cobrança</Link>
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
                        placeholder="Descrição, pagador, número ou referência"
                        className="w-64"
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Situação"
                        className="w-40"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData('status', event.target.value)
                        }
                    >
                        <option value="">Todas as situações</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
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
                    <NativeSelect
                        aria-label="Provedor"
                        className="w-36"
                        value={form.data.provider}
                        onChange={(event) =>
                            form.setData('provider', event.target.value)
                        }
                    >
                        <option value="">Todos</option>
                        {providers.map((provider) => (
                            <option key={provider.value} value={provider.value}>
                                {provider.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        aria-label="Criadas a partir de"
                        className="w-40"
                        value={form.data.from}
                        onChange={(event) =>
                            form.setData('from', event.target.value)
                        }
                    />
                    <Input
                        type="date"
                        aria-label="Criadas até"
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
                                <th className="p-3">Cobrança</th>
                                <th className="p-3">Pagador</th>
                                <th className="p-3">Origem</th>
                                <th className="p-3">Vencimento</th>
                                <th className="p-3">Situação</th>
                                <th className="p-3 text-right">Valor</th>
                                <th className="p-3 text-right">Recebido</th>
                            </tr>
                        </thead>
                        <tbody>
                            {charges.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhuma cobrança encontrada.
                                    </td>
                                </tr>
                            )}
                            {charges.data.map((charge) => (
                                <tr
                                    key={charge.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <Link
                                            href={show(charge.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            #{charge.number} ·{' '}
                                            {charge.description}
                                        </Link>
                                        <span className="mt-1 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                            {charge.provider_name ??
                                                'Sem provedor'}
                                            <EnvironmentBadge
                                                environment={charge.environment}
                                            />
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {charge.payer_name ??
                                            'Não identificado'}
                                    </td>
                                    <td className="p-3">
                                        {charge.origin_label}
                                        {charge.origin_reference && (
                                            <span className="block text-xs text-muted-foreground">
                                                {charge.origin_reference}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {formatDate(charge.due_date)}
                                    </td>
                                    <td className="p-3">
                                        <ChargeStatusBadge
                                            status={charge.status}
                                            label={charge.status_label}
                                        />
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(charge.amount)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatMoney(charge.paid_amount)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={charges.links} />
            </div>
        </>
    );
}

PaymentCharges.layout = {
    breadcrumbs: [paymentsBreadcrumb, { title: 'Cobranças', href: index() }],
};
