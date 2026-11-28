import { Head, Link, router } from '@inertiajs/react';
import { fiscalBreadcrumb } from '@/components/fiscal/fiscal-nav';
import {
    FiscalEnvironmentBadge,
    FiscalStatusBadge,
} from '@/components/fiscal/fiscal-status';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { formatMoney } from '@/lib/decimal';
import { dashboard, reconcile, settings } from '@/routes/fiscal';
import { create, index, show } from '@/routes/fiscal/documents';
import { index as reports } from '@/routes/fiscal/reports';
import { reprocess } from '@/routes/fiscal/webhook-events';
import type { FiscalDocument, FiscalReadiness, Option } from '@/types';

export default function FiscalDashboard({
    indicators,
    statuses,
    readiness,
    environment,
    attention,
    failedEvents,
    abilities,
}: {
    indicators: {
        by_status: Record<string, number>;
        authorized_total: string;
        authorized_count: number;
        pending: number;
        rejected: number;
    };
    statuses: Option[];
    readiness: FiscalReadiness[];
    environment: string;
    attention: FiscalDocument[];
    failedEvents: {
        id: number;
        event: string;
        attempts: number;
        error: string | null;
        created_at: string | null;
    }[];
    abilities: {
        create: boolean;
        settings: boolean;
        reprocess: boolean;
        reports: boolean;
    };
}) {
    const { formatDateTime } = useTenant();

    return (
        <>
            <Head title="Documentos fiscais" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-1">
                        <Heading
                            variant="small"
                            title="Documentos fiscais"
                            description="NFS-e, NF-e e NFC-e da empresa. Emitir um documento não registra venda, recebimento, estoque nem caixa."
                        />
                        <FiscalEnvironmentBadge environment={environment} />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {abilities.reprocess && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        reconcile.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Consultar pendentes
                            </Button>
                        )}
                        {abilities.reports && (
                            <Button variant="outline" asChild>
                                <Link href={reports()}>Relatórios</Link>
                            </Button>
                        )}
                        {abilities.create && (
                            <Button asChild>
                                <Link href={create()}>Novo documento</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        [
                            'Autorizados no mês',
                            String(indicators.authorized_count),
                        ],
                        [
                            'Valor autorizado no mês',
                            formatMoney(indicators.authorized_total),
                        ],
                        ['Em processamento', String(indicators.pending)],
                        [
                            'Rejeitados ou denegados',
                            String(indicators.rejected),
                        ],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-4">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-2xl font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>

                <section
                    aria-label="Prontidão para emissão"
                    className="space-y-2"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-medium">Prontidão para emissão</h3>
                        {abilities.settings && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={settings()}>
                                    Configurações fiscais
                                </Link>
                            </Button>
                        )}
                    </div>
                    <div className="grid gap-3 md:grid-cols-3">
                        {readiness.map((item) => (
                            <div
                                key={item.type}
                                className="space-y-2 rounded-lg border p-4 text-sm"
                            >
                                <p className="font-medium">{item.label}</p>
                                {item.blockers.length === 0 ? (
                                    <p className="text-emerald-700 dark:text-emerald-400">
                                        Pronto para emitir.
                                    </p>
                                ) : (
                                    <ul className="list-disc space-y-1 pl-4 text-muted-foreground">
                                        {item.blockers.map((blocker) => (
                                            <li key={blocker}>{blocker}</li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        ))}
                    </div>
                </section>

                <section
                    aria-label="Situação dos documentos"
                    className="space-y-2"
                >
                    <h3 className="font-medium">Documentos por situação</h3>
                    <div className="flex flex-wrap gap-2">
                        {statuses.map((status) => (
                            <Link
                                key={status.value}
                                href={index({
                                    query: { status: status.value },
                                })}
                                className="rounded-lg border px-3 py-2 text-sm hover:bg-muted"
                            >
                                {status.label}:{' '}
                                <strong>
                                    {indicators.by_status[status.value] ?? 0}
                                </strong>
                            </Link>
                        ))}
                    </div>
                </section>

                <section aria-label="Pedem atenção" className="space-y-2">
                    <h3 className="font-medium">Pedem atenção</h3>
                    {attention.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nenhum documento pendente ou rejeitado.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {attention.map((document) => (
                                <li
                                    key={document.id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                                >
                                    <span>
                                        <Link
                                            href={show(document.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {document.type_label} interno{' '}
                                            {document.local_number}
                                        </Link>{' '}
                                        ·{' '}
                                        {document.receiver_name ??
                                            'Sem destinatário'}{' '}
                                        · {formatMoney(document.total)}
                                        {document.error_message && (
                                            <span className="block text-xs text-destructive">
                                                {document.error_message}
                                            </span>
                                        )}
                                    </span>
                                    <FiscalStatusBadge
                                        status={document.status}
                                        label={document.status_label}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {failedEvents.length > 0 && (
                    <section
                        aria-label="Notificações com falha"
                        className="space-y-2"
                    >
                        <h3 className="font-medium">Notificações com falha</h3>
                        <ul className="divide-y rounded-lg border text-sm">
                            {failedEvents.map((event) => (
                                <li
                                    key={event.id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                                >
                                    <span>
                                        {event.event} ·{' '}
                                        {event.created_at
                                            ? formatDateTime(event.created_at)
                                            : '—'}{' '}
                                        · {event.attempts} tentativa(s)
                                        {event.error && (
                                            <span className="block text-xs text-muted-foreground">
                                                {event.error}
                                            </span>
                                        )}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                reprocess.url(event.id),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Reprocessar
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

FiscalDashboard.layout = {
    breadcrumbs: [{ ...fiscalBreadcrumb, href: dashboard() }],
};
