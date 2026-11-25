import { Head, router, usePage } from '@inertiajs/react';
import ConfirmButton from '@/components/confirm-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/root-admin/companies';
import { destroy, store } from '@/routes/root-admin/companies/modules';

type Company = {
    id: number;
    name: string;
    slug: string;
    status: string;
    active_modules: number[];
};

type PlatformModule = {
    id: number;
    identifier: string;
    name: string;
    development_status: string;
    dependencies: string[] | null;
};

export default function Companies({
    companies,
    modules,
}: {
    companies: Company[];
    modules: PlatformModule[];
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Empresas" />
            <section className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Empresas"
                    description="Módulos autorizados por empresa. Desativar preserva os dados."
                />
                <InputError message={errors.module} />
                <ul className="divide-y rounded-lg border">
                    {companies.map((company) => (
                        <li className="space-y-3 p-4" key={company.id}>
                            <p className="font-medium">
                                {company.name}{' '}
                                <span className="text-sm font-normal text-muted-foreground">
                                    {company.slug} · {company.status}
                                </span>
                            </p>
                            <ul className="flex flex-wrap gap-2">
                                {modules.map((module) => {
                                    const parameters = {
                                        company: company.id,
                                        module: module.id,
                                    };

                                    return (
                                        <li key={module.id}>
                                            {company.active_modules.includes(
                                                module.id,
                                            ) ? (
                                                <ConfirmButton
                                                    size="sm"
                                                    route={destroy(parameters)}
                                                    title={`Desativar ${module.name}`}
                                                    description={`Usuários de ${company.name} perdem o acesso ao módulo. Os dados são preservados.`}
                                                    confirmLabel="Desativar"
                                                >
                                                    {module.name}: ativo
                                                </ConfirmButton>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.visit(
                                                            store(parameters),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Ativar {module.name}
                                                </Button>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </li>
                    ))}
                </ul>
            </section>
        </>
    );
}

Companies.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: index() },
        { title: 'Empresas', href: index() },
    ],
};
