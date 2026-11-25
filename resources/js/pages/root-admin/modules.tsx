import { Head, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { index, update } from '@/routes/root-admin/modules';

type PlatformModule = {
    id: number;
    identifier: string;
    name: string;
    description: string;
    version: string;
    development_status: string;
    commercially_available: boolean;
    permissions: { key: string; label: string }[] | null;
};

export default function Modules({ modules }: { modules: PlatformModule[] }) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Catálogo de módulos" />
            <section className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Catálogo de módulos"
                    description="Disponibilizar comercialmente não ativa o módulo em nenhuma empresa."
                />
                <InputError message={errors.commercially_available} />
                <div className="grid gap-4 md:grid-cols-2">
                    {modules.map((module) => {
                        const isImplemented =
                            module.development_status === 'implemented';

                        return (
                            <article
                                className="space-y-3 rounded-lg border p-4"
                                key={module.id}
                            >
                                <div>
                                    <h2 className="font-medium">
                                        {module.name}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {module.description}
                                    </p>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {module.identifier} · versão{' '}
                                    {module.version} ·{' '}
                                    {isImplemented
                                        ? 'implementado'
                                        : 'em desenvolvimento'}{' '}
                                    · {module.permissions?.length ?? 0}{' '}
                                    permissões
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        !isImplemented &&
                                        !module.commercially_available
                                    }
                                    onClick={() =>
                                        router.visit(update(module.id), {
                                            data: {
                                                commercially_available:
                                                    !module.commercially_available,
                                            },
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {module.commercially_available
                                        ? 'Retirar da venda'
                                        : 'Disponibilizar comercialmente'}
                                </Button>
                            </article>
                        );
                    })}
                </div>
            </section>
        </>
    );
}

Modules.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: index() },
        { title: 'Catálogo de módulos', href: index() },
    ],
};
