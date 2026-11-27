import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { home } from '@/routes/root-admin';
import { index } from '@/routes/root-admin/payments';
import { update } from '@/routes/root-admin/payments/providers';

type Provider = {
    id: number;
    identifier: string;
    name: string;
    is_available: boolean;
    capabilities: Record<string, boolean>;
    accounts: number;
};

const CAPABILITY_LABELS: Record<string, string> = {
    pix: 'Pix',
    boleto: 'Boleto',
    credit_card: 'Cartão',
    payment_link: 'Link',
    query: 'Consulta',
    cancel: 'Cancelamento',
    refund: 'Devolução',
    webhooks: 'Webhooks',
};

export default function RootAdminPayments({
    providers,
    indicators,
    failing,
    events,
}: {
    providers: Provider[];
    indicators: {
        companies: number;
        active_accounts: number;
        failing_accounts: number;
        charges_last_30_days: number;
        failed_events: number;
    };
    failing: {
        id: number;
        company: string | null;
        provider: string;
        environment: string;
        status_label: string;
        last_error: string | null;
        updated_at: string | null;
    }[];
    events: {
        id: number;
        company: string | null;
        provider: string;
        status: string;
        status_label: string;
        attempts: number;
        error: string | null;
        created_at: string | null;
    }[];
}) {
    const { formatDateTime } = useTenant();

    return (
        <>
            <Head title="Pagamentos na plataforma" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Pagamentos"
                    description="Provedores disponíveis e situação técnica das integrações. Valores e dados financeiros das empresas não são exibidos aqui."
                />

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        ['Empresas com Pagamentos', indicators.companies],
                        ['Contas conectadas', indicators.active_accounts],
                        ['Contas com falha', indicators.failing_accounts],
                        [
                            'Cobranças (30 dias)',
                            indicators.charges_last_30_days,
                        ],
                        ['Eventos com falha', indicators.failed_events],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-2xl font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>

                <section aria-label="Provedores" className="space-y-3">
                    <h3 className="font-medium">Provedores</h3>
                    <ul className="grid gap-3 md:grid-cols-2">
                        {providers.map((provider) => (
                            <li
                                key={provider.id}
                                className="space-y-2 rounded-lg border p-4"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <p className="font-medium">
                                        {provider.name}
                                    </p>
                                    <Badge
                                        variant={
                                            provider.is_available
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {provider.is_available
                                            ? 'Disponível'
                                            : 'Indisponível'}
                                    </Badge>
                                </div>
                                <p className="flex flex-wrap gap-1 text-xs">
                                    {Object.entries(provider.capabilities)
                                        .filter(([, enabled]) => enabled)
                                        .map(([key]) => (
                                            <Badge
                                                key={key}
                                                variant="secondary"
                                            >
                                                {CAPABILITY_LABELS[key] ?? key}
                                            </Badge>
                                        ))}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {provider.accounts} conta(s) configurada(s)
                                    por empresas.
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.patch(
                                            update.url(provider.id),
                                            {
                                                is_available:
                                                    !provider.is_available,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {provider.is_available
                                        ? 'Tornar indisponível'
                                        : 'Disponibilizar para as empresas'}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>

                <section
                    aria-label="Integrações com falha"
                    className="space-y-3"
                >
                    <h3 className="font-medium">Integrações com falha</h3>
                    <ul className="divide-y rounded-lg border text-sm">
                        {failing.length === 0 && (
                            <li className="p-4 text-muted-foreground">
                                Nenhuma integração com falha.
                            </li>
                        )}
                        {failing.map((account) => (
                            <li key={account.id} className="p-3">
                                <span className="font-medium">
                                    {account.company ?? '—'}
                                </span>{' '}
                                · {account.provider} (
                                {account.environment === 'production'
                                    ? 'produção'
                                    : 'homologação'}
                                ) · {account.status_label}
                                {account.last_error && (
                                    <span className="block text-xs text-muted-foreground">
                                        {account.last_error}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <section aria-label="Eventos técnicos" className="space-y-3">
                    <h3 className="font-medium">Últimos eventos de webhook</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Recebido em</th>
                                    <th className="p-3">Empresa</th>
                                    <th className="p-3">Provedor</th>
                                    <th className="p-3">Situação</th>
                                    <th className="p-3">Erro</th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            Nenhum evento recebido.
                                        </td>
                                    </tr>
                                )}
                                {events.map((event) => (
                                    <tr key={event.id} className="border-t">
                                        <td className="p-3 whitespace-nowrap">
                                            {event.created_at
                                                ? formatDateTime(
                                                      event.created_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="p-3">
                                            {event.company ?? '—'}
                                        </td>
                                        <td className="p-3">
                                            {event.provider}
                                        </td>
                                        <td className="p-3">
                                            <Badge
                                                variant={
                                                    event.status === 'failed'
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                {event.status_label}
                                            </Badge>{' '}
                                            <span className="text-xs text-muted-foreground">
                                                ({event.attempts})
                                            </span>
                                        </td>
                                        <td className="p-3 text-xs">
                                            {event.error ?? '—'}
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

RootAdminPayments.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Pagamentos', href: index() },
    ],
};
