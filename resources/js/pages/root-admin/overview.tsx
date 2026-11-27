import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { useTenant } from '@/hooks/use-tenant';
import { home } from '@/routes/root-admin';
import { index as audit } from '@/routes/root-admin/audit';
import { index as companies } from '@/routes/root-admin/companies';
import { index as modules } from '@/routes/root-admin/modules';
import { index as users } from '@/routes/root-admin/users';
import type { PlatformAuditEntry } from '@/types';

export default function PlatformOverview({
    companies: byStatus,
    users: userCounts,
    modules: moduleRows,
    recent,
}: {
    companies: Record<string, number>;
    users: {
        total: number;
        root_admins: number;
        without_company: number;
        verified: number;
    };
    modules: {
        id: number;
        name: string;
        development_status: string;
        commercially_available: boolean;
        active_companies: number;
    }[];
    recent: PlatformAuditEntry[];
}) {
    const { formatDateTime } = useTenant();
    const totalCompanies = Object.values(byStatus).reduce(
        (sum, count) => sum + count,
        0,
    );
    const tiles = [
        {
            label: 'Empresas',
            value: totalCompanies,
            hint: `${byStatus.active ?? 0} ativas · ${byStatus.suspended ?? 0} suspensas`,
            href: companies(),
        },
        {
            label: 'Usuários',
            value: userCounts.total,
            hint: `${userCounts.verified} com e-mail verificado`,
            href: users(),
        },
        {
            label: 'Usuários sem empresa',
            value: userCounts.without_company,
            hint: 'Cadastrados sem vínculo empresarial',
            href: users(),
        },
        {
            label: 'Administradores',
            value: userCounts.root_admins,
            hint: 'RootAdmins ativos',
            href: users(),
        },
    ];

    return (
        <>
            <Head title="Administração da plataforma" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Visão geral da plataforma"
                    description="Contagens reais do banco. Indicadores de faturamento e uso ainda não existem na plataforma."
                />
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {tiles.map((tile) => (
                        <Link
                            key={tile.label}
                            href={tile.href}
                            className="rounded-lg border p-4 hover:bg-muted/40"
                        >
                            <p className="text-sm text-muted-foreground">
                                {tile.label}
                            </p>
                            <p className="mt-1 text-2xl font-semibold">
                                {tile.value}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {tile.hint}
                            </p>
                        </Link>
                    ))}
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <section
                        className="space-y-3 rounded-lg border p-4"
                        aria-label="Módulos"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="font-medium">Módulos contratados</h3>
                            <Link
                                className="text-sm underline"
                                href={modules()}
                            >
                                Catálogo
                            </Link>
                        </div>
                        <ul className="divide-y text-sm">
                            {moduleRows.map((module) => (
                                <li
                                    key={module.id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-2"
                                >
                                    <span className="flex flex-wrap items-center gap-2">
                                        {module.name}
                                        <Badge
                                            variant={
                                                module.development_status ===
                                                'implemented'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {module.development_status ===
                                            'implemented'
                                                ? 'Implementado'
                                                : 'Em desenvolvimento'}
                                        </Badge>
                                        {module.commercially_available && (
                                            <Badge>À venda</Badge>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {module.active_companies} empresa(s)
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                    <section
                        className="space-y-3 rounded-lg border p-4"
                        aria-label="Atividade recente"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="font-medium">Atividade recente</h3>
                            <Link className="text-sm underline" href={audit()}>
                                Auditoria completa
                            </Link>
                        </div>
                        {recent.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum registro de auditoria.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {recent.map((entry) => (
                                    <li key={entry.id} className="py-2">
                                        <span className="font-mono text-xs">
                                            {entry.event}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(entry.occurred_at)}{' '}
                                            · {entry.user ?? 'Sistema'}
                                            {entry.company &&
                                                ` · ${entry.company}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

PlatformOverview.layout = {
    breadcrumbs: [{ title: 'RootAdmin', href: home() }],
};
