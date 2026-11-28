import { Head, router, usePage } from '@inertiajs/react';
import ApplicationIcon from '@/components/commercial/application-icon';
import SubscriptionNotice from '@/components/commercial/subscription-notice';
import ConfirmButton from '@/components/confirm-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/decimal';
import { destroy, index, store } from '@/routes/company/applications';
import type { CatalogApplication, SubscriptionSummary } from '@/types';

export default function CompanyApplications({
    applications,
    active,
    subscription,
    canManage,
}: {
    applications: CatalogApplication[];
    active: string[];
    subscription: SubscriptionSummary;
    canManage: boolean;
}) {
    const { errors } = usePage().props;
    const nameOf = (identifier: string) =>
        applications.find((app) => app.identifier === identifier)?.name ??
        identifier;
    const dependentsOf = (identifier: string) =>
        applications
            .filter(
                (app) =>
                    active.includes(app.identifier) &&
                    app.dependencies.includes(identifier),
            )
            .map((app) => app.name);
    const contracted = applications.filter((app) =>
        active.includes(app.identifier),
    );
    const others = applications.filter(
        (app) => !active.includes(app.identifier),
    );
    const total = contracted.reduce(
        (sum, app) =>
            sum +
            (app.monthly_price
                ? Math.round(Number(app.monthly_price) * 100)
                : 0),
        0,
    );

    return (
        <>
            <Head title="Aplicações da empresa" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Aplicações da empresa"
                    description="Inclua as aplicações de que precisa. Dependências entram automaticamente e remover uma aplicação preserva os dados."
                />
                <SubscriptionNotice subscription={subscription} />
                <InputError
                    message={
                        (errors as Record<string, string | undefined>).module ??
                        (errors as Record<string, string | undefined>)
                            .modules ??
                        (errors as Record<string, string | undefined>)
                            .subscription
                    }
                />
                {!canManage && (
                    <p className="text-sm text-muted-foreground">
                        Somente o proprietário da empresa pode incluir ou
                        remover aplicações.
                    </p>
                )}

                <section aria-labelledby="contratadas" className="space-y-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="contratadas" className="font-medium">
                            Habilitadas ({contracted.length})
                        </h2>
                        {total > 0 && (
                            <span className="text-sm text-muted-foreground">
                                Valor de referência:{' '}
                                {formatMoney((total / 100).toFixed(2))}/mês
                            </span>
                        )}
                    </div>
                    {contracted.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            Nenhuma aplicação habilitada.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {contracted.map((app) => {
                                const dependents = dependentsOf(app.identifier);

                                return (
                                    <li
                                        key={app.identifier}
                                        className="flex flex-wrap items-center justify-between gap-3 p-4"
                                    >
                                        <span className="flex items-center gap-3">
                                            <ApplicationIcon
                                                name={app.icon}
                                                className="size-5 text-brand"
                                            />
                                            <span>
                                                <span className="block font-medium">
                                                    {app.name}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {app.monthly_price
                                                        ? `${formatMoney(app.monthly_price)}/mês`
                                                        : 'Valor sob consulta'}
                                                    {dependents.length > 0 &&
                                                        ` · Necessária para ${dependents.join(', ')}`}
                                                </span>
                                            </span>
                                        </span>
                                        {canManage && (
                                            <ConfirmButton
                                                size="sm"
                                                disabled={dependents.length > 0}
                                                route={destroy(app.identifier)}
                                                title={`Remover ${app.name}?`}
                                                description="Os usuários perdem o acesso à aplicação. Os dados registrados são preservados e voltam se ela for incluída de novo."
                                                confirmLabel="Remover"
                                            >
                                                Remover
                                            </ConfirmButton>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>

                {others.length > 0 && (
                    <section
                        aria-labelledby="disponiveis"
                        className="space-y-3"
                    >
                        <h2 id="disponiveis" className="font-medium">
                            Disponíveis para incluir
                        </h2>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {others.map((app) => {
                                const missing = app.dependencies.filter(
                                    (dependency) =>
                                        !active.includes(dependency),
                                );

                                return (
                                    <article
                                        key={app.identifier}
                                        className="flex flex-col rounded-xl border p-4"
                                    >
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-2 font-medium">
                                                <ApplicationIcon
                                                    name={app.icon}
                                                    className="size-5 text-brand"
                                                />
                                                {app.name}
                                            </span>
                                            <Badge variant="outline">
                                                {app.category}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {app.tagline ?? app.description}
                                        </p>
                                        {missing.length > 0 && (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Inclui também:{' '}
                                                {missing.map(nameOf).join(', ')}
                                                .
                                            </p>
                                        )}
                                        <div className="mt-auto flex items-center justify-between gap-2 pt-4">
                                            <span className="text-sm">
                                                {app.monthly_price
                                                    ? `${formatMoney(app.monthly_price)}/mês`
                                                    : 'Valor sob consulta'}
                                            </span>
                                            {canManage && (
                                                <Button
                                                    size="sm"
                                                    disabled={
                                                        subscription.blocks_writes
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            store.url(),
                                                            {
                                                                modules: [
                                                                    app.identifier,
                                                                ],
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Incluir
                                                </Button>
                                            )}
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

CompanyApplications.layout = {
    breadcrumbs: [{ title: 'Aplicações da empresa', href: index() }],
};
