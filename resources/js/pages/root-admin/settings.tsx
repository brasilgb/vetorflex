import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { home, settings as settingsRoute } from '@/routes/root-admin';

type Setting = { group: string; label: string; value: string | null };

export default function PlatformSettings({
    settings,
}: {
    settings: Setting[];
}) {
    const groups = [...new Set(settings.map((setting) => setting.group))];

    return (
        <>
            <Head title="Configurações da plataforma" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Configurações da plataforma"
                    description="Consulta da configuração em uso. Os valores são definidos no ambiente do servidor (.env) e segredos não são exibidos."
                />
                <div className="grid gap-4 lg:grid-cols-2">
                    {groups.map((group) => (
                        <section
                            key={group}
                            className="space-y-2 rounded-lg border p-4"
                            aria-label={group}
                        >
                            <h3 className="font-medium">{group}</h3>
                            <dl className="divide-y text-sm">
                                {settings
                                    .filter(
                                        (setting) => setting.group === group,
                                    )
                                    .map((setting) => (
                                        <div
                                            key={setting.label}
                                            className="flex flex-wrap justify-between gap-2 py-2"
                                        >
                                            <dt className="text-muted-foreground">
                                                {setting.label}
                                            </dt>
                                            <dd className="text-right break-all">
                                                {setting.value ?? '—'}
                                            </dd>
                                        </div>
                                    ))}
                            </dl>
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}

PlatformSettings.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Configurações', href: settingsRoute() },
    ],
};
