import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

type PlatformModule = { id: number; identifier: string; name: string; description: string; development_status: string };

export default function Modules({ modules }: { modules: PlatformModule[] }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'RootAdmin', href: '/rootadmin/modules' }, { title: 'Catálogo', href: '/rootadmin/modules' }]}>
            <Head title="Catálogo de módulos" />
            <section className="p-6">
                <h1 className="text-2xl font-semibold">Catálogo de módulos</h1>
                <div className="mt-6 grid gap-4 md:grid-cols-2">
                    {modules.map((module) => <article className="rounded-lg border p-4" key={module.id}><h2 className="font-medium">{module.name}</h2><p className="mt-1 text-sm text-muted-foreground">{module.description}</p><p className="mt-3 text-xs uppercase text-muted-foreground">{module.identifier} · {module.development_status}</p></article>)}
                </div>
            </section>
        </AppLayout>
    );
}
