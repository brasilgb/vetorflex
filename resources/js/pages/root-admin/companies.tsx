import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

type Company = { id: number; name: string; slug: string; status: string };

export default function Companies({ companies }: { companies: Company[] }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'RootAdmin', href: '/rootadmin/companies' }, { title: 'Empresas', href: '/rootadmin/companies' }]}>
            <Head title="Empresas" />
            <section className="p-6">
                <h1 className="text-2xl font-semibold">Empresas</h1>
                <p className="mt-1 text-sm text-muted-foreground">Empresas cadastradas na plataforma.</p>
                <ul className="mt-6 divide-y rounded-lg border">
                    {companies.map((company) => <li className="p-4" key={company.id}>{company.name} <span className="text-sm text-muted-foreground">{company.slug} · {company.status}</span></li>)}
                </ul>
            </section>
        </AppLayout>
    );
}
