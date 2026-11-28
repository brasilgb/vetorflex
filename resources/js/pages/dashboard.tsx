import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Settings2 } from 'lucide-react';
import ApplicationIcon from '@/components/commercial/application-icon';
import SubscriptionNotice from '@/components/commercial/subscription-notice';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { update as selectCompany } from '@/routes/active-company';
import { index as applicationsRoute } from '@/routes/company/applications';
import { home as rootAdminHome } from '@/routes/root-admin';
import type { SubscriptionSummary } from '@/types';

type Overview = {
    company: { id: number; name: string };
    subscription: SubscriptionSummary;
    applications: {
        identifier: string;
        name: string;
        tagline: string | null;
        icon: string | null;
        href: string | null;
        steps: string[];
        needs_setup: boolean;
        indicators: { label: string; value: number | string }[];
    }[];
};

export default function Dashboard({
    overview,
    companies,
    pendingSignup,
    isRootAdmin,
}: {
    overview: Overview | null;
    companies: { id: number; name: string }[];
    pendingSignup: boolean;
    isRootAdmin: boolean;
}) {
    if (overview === null) {
        return (
            <>
                <Head title="Painel" />
                <div className="mx-auto max-w-2xl space-y-6 p-4 md:p-6">
                    <Heading
                        variant="small"
                        title="Bem-vindo ao VetorFlex"
                        description="Escolha a empresa para continuar."
                    />
                    {pendingSignup && (
                        <div className="space-y-3 rounded-lg border border-amber-500/60 p-4 text-sm">
                            <p>
                                O ambiente da sua empresa ainda não terminou de
                                ser criado. Tentaremos de novo agora.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => router.reload()}
                            >
                                Tentar novamente
                            </Button>
                        </div>
                    )}
                    {companies.length > 0 ? (
                        <ul className="divide-y rounded-lg border">
                            {companies.map((company) => (
                                <li
                                    key={company.id}
                                    className="flex items-center justify-between gap-3 p-4"
                                >
                                    <span className="font-medium">
                                        {company.name}
                                    </span>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                selectCompany.url(),
                                                { company_id: company.id },
                                                {
                                                    onSuccess: () =>
                                                        router.visit(
                                                            dashboard(),
                                                        ),
                                                },
                                            )
                                        }
                                    >
                                        Acessar
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        !pendingSignup && (
                            <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                {isRootAdmin
                                    ? 'Sua conta é de administração da plataforma.'
                                    : 'Sua conta ainda não está vinculada a nenhuma empresa. Peça ao responsável da empresa para convidá-lo.'}
                            </p>
                        )
                    )}
                    {isRootAdmin && (
                        <Button asChild>
                            <Link href={rootAdminHome()}>
                                Ir para o RootAdmin
                            </Link>
                        </Button>
                    )}
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Painel" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title={`Olá! Esta é a ${overview.company.name}`}
                        description="Suas aplicações, os números de hoje e os próximos passos."
                    />
                    <Button variant="outline" asChild>
                        <Link href={applicationsRoute()}>
                            <Settings2 className="size-4" aria-hidden="true" />{' '}
                            Gerenciar aplicações
                        </Link>
                    </Button>
                </div>
                <SubscriptionNotice subscription={overview.subscription} />
                {overview.applications.length === 0 ? (
                    <div className="space-y-3 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        <p>Nenhuma aplicação habilitada para esta empresa.</p>
                        <Button asChild>
                            <Link href={applicationsRoute()}>
                                Escolher aplicações
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {overview.applications.map((app) => (
                            <article
                                key={app.identifier}
                                className="flex flex-col rounded-xl border p-5"
                            >
                                <div className="mb-3 flex items-start justify-between gap-2">
                                    <span className="flex items-center gap-3">
                                        <span className="flex size-10 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                            <ApplicationIcon
                                                name={app.icon}
                                                className="size-5"
                                            />
                                        </span>
                                        <span>
                                            <span className="block font-semibold">
                                                {app.name}
                                            </span>
                                            {app.tagline && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {app.tagline}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                    {app.needs_setup && (
                                        <Badge variant="outline">
                                            Requer configuração
                                        </Badge>
                                    )}
                                </div>
                                <dl className="mb-3 grid grid-cols-2 gap-2">
                                    {app.indicators.map((indicator) => (
                                        <div
                                            key={indicator.label}
                                            className="rounded-lg bg-muted/60 p-2"
                                        >
                                            <dt className="text-xs text-muted-foreground">
                                                {indicator.label}
                                            </dt>
                                            <dd className="text-lg font-semibold">
                                                {indicator.value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                                <details
                                    className="mb-3 text-sm"
                                    open={app.indicators.every(
                                        (indicator) => indicator.value === 0,
                                    )}
                                >
                                    <summary className="cursor-pointer font-medium">
                                        Como começar
                                    </summary>
                                    <ol className="mt-2 list-decimal space-y-1 pl-5 text-muted-foreground">
                                        {app.steps.map((step) => (
                                            <li key={step}>{step}</li>
                                        ))}
                                    </ol>
                                </details>
                                {app.href && (
                                    <Button
                                        variant="outline"
                                        asChild
                                        className="mt-auto"
                                    >
                                        <Link href={app.href}>
                                            Abrir {app.name}{' '}
                                            <ArrowRight
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                    </Button>
                                )}
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Painel', href: dashboard() }],
};
