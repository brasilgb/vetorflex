import { Head, router, useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { home } from '@/routes/root-admin';
import { index } from '@/routes/root-admin/fiscal';
import { release } from '@/routes/root-admin/fiscal/companies';
import { test, update, webhook } from '@/routes/root-admin/fiscal/providers';
import type { Option } from '@/types';

type Provider = {
    id: number;
    identifier: string;
    name: string;
    is_available: boolean;
    document_types: string[];
    environment: string;
    has_owner_key: boolean;
    has_webhook_secret: boolean;
    webhook_registered_at: string | null;
    webhook_url: string;
    last_error: string | null;
    capabilities: Record<string, boolean | string[]>;
};

type CompanySetting = {
    id: number;
    company: string | null;
    environment: string;
    is_registered: boolean;
    registration_error: string | null;
    certificate_expires_at: string | null;
    tax_settings_confirmed: boolean;
    production_released_at: string | null;
    documents: number;
};

function ProviderCard({
    provider,
    types,
}: {
    provider: Provider;
    types: Option[];
}) {
    const { formatDateTime } = useTenant();
    const supported =
        (provider.capabilities.types as string[] | undefined) ?? [];
    const form = useForm({
        is_available: provider.is_available,
        document_types: provider.document_types,
        environment: provider.environment,
        owner_api_key: '',
    });

    return (
        <section className="space-y-4 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-medium">{provider.name}</h3>
                <Badge variant={provider.is_available ? 'default' : 'outline'}>
                    {provider.is_available ? 'Disponível' : 'Indisponível'}
                </Badge>
                <Badge variant="outline">
                    {provider.environment === 'production'
                        ? 'Produção'
                        : 'Sandbox'}
                </Badge>
            </div>
            <p className="text-xs text-muted-foreground">
                Capacidades declaradas:{' '}
                {Object.entries(provider.capabilities)
                    .filter(([key, value]) => key !== 'types' && value === true)
                    .map(([key]) => key)
                    .join(', ') || 'nenhuma'}
                . Contingência e carta de correção não são oferecidas.
            </p>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(update.url(provider.id), {
                        preserveScroll: true,
                        onSuccess: () => form.setData('owner_api_key', ''),
                    });
                }}
            >
                <CheckboxField
                    id={`${provider.identifier}-available`}
                    label="Disponível para as empresas"
                    checked={form.data.is_available}
                    onChange={(checked) =>
                        form.setData('is_available', checked)
                    }
                />
                <div className="space-y-2">
                    <p className="text-sm font-medium">Documentos liberados</p>
                    {types.map((type) => (
                        <CheckboxField
                            key={type.value}
                            id={`${provider.identifier}-${type.value}`}
                            label={type.label}
                            disabled={!supported.includes(type.value)}
                            checked={form.data.document_types.includes(
                                type.value,
                            )}
                            onChange={(checked) =>
                                form.setData(
                                    'document_types',
                                    checked
                                        ? [
                                              ...form.data.document_types,
                                              type.value,
                                          ]
                                        : form.data.document_types.filter(
                                              (value) => value !== type.value,
                                          ),
                                )
                            }
                        />
                    ))}
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <FormField
                        id={`${provider.identifier}-environment`}
                        label="Conta da plataforma"
                        error={form.errors.environment}
                    >
                        <NativeSelect
                            id={`${provider.identifier}-environment`}
                            value={form.data.environment}
                            onChange={(event) =>
                                form.setData('environment', event.target.value)
                            }
                        >
                            <option value="sandbox">Sandbox (testes)</option>
                            <option value="production">Produção</option>
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id={`${provider.identifier}-key`}
                        label="Chave de API da empresa titular"
                        hint={
                            provider.has_owner_key
                                ? 'Informada. Deixe em branco para manter.'
                                : 'Guardada criptografada; nunca é exibida.'
                        }
                        error={form.errors.owner_api_key}
                    >
                        <Input
                            id={`${provider.identifier}-key`}
                            type="password"
                            autoComplete="new-password"
                            value={form.data.owner_api_key}
                            onChange={(event) =>
                                form.setData(
                                    'owner_api_key',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                </div>
                <Button disabled={form.processing}>Salvar</Button>
            </form>
            <div className="flex flex-wrap items-center gap-2 border-t pt-3 text-sm">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!provider.has_owner_key}
                    onClick={() =>
                        router.post(
                            test.url(provider.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Testar conexão
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!provider.has_owner_key}
                    onClick={() =>
                        router.post(
                            webhook.url(provider.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Registrar webhook
                </Button>
                <span className="text-xs break-all text-muted-foreground">
                    {provider.webhook_registered_at
                        ? `Webhook registrado em ${formatDateTime(provider.webhook_registered_at)} (${provider.webhook_url}).`
                        : `Webhook não registrado. Endereço: ${provider.webhook_url}`}
                </span>
            </div>
            {provider.last_error && (
                <p className="text-sm text-destructive">
                    {provider.last_error}
                </p>
            )}
        </section>
    );
}

export default function RootAdminFiscal({
    providers,
    types,
    companies,
    indicators,
}: {
    providers: Provider[];
    types: Option[];
    companies: CompanySetting[];
    indicators: {
        authorized_last_30_days: number;
        rejected: number;
        pending: number;
        failed_events: number;
    };
}) {
    const { formatDateTime } = useTenant();

    return (
        <>
            <Head title="Documentos fiscais — plataforma" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Documentos fiscais"
                    description="Provedor fiscal da plataforma, documentos liberados e liberação de produção por empresa. Somente contagens; nenhum dado fiscal das empresas."
                />
                <dl className="grid gap-3 sm:grid-cols-4">
                    {[
                        [
                            'Autorizados (30 dias)',
                            indicators.authorized_last_30_days,
                        ],
                        ['Rejeitados/denegados', indicators.rejected],
                        ['Pendentes', indicators.pending],
                        ['Notificações com falha', indicators.failed_events],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-3">
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-xl font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>
                {providers.map((provider) => (
                    <ProviderCard
                        key={provider.id}
                        provider={provider}
                        types={types}
                    />
                ))}
                <section className="space-y-2">
                    <h3 className="font-medium">Empresas</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Empresa</th>
                                    <th className="p-3">Ambiente</th>
                                    <th className="p-3">Emissora</th>
                                    <th className="p-3">Certificado</th>
                                    <th className="p-3">
                                        Tributação confirmada
                                    </th>
                                    <th className="p-3">Documentos</th>
                                    <th className="p-3">Produção</th>
                                </tr>
                            </thead>
                            <tbody>
                                {companies.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            Nenhuma empresa configurou a emissão
                                            fiscal.
                                        </td>
                                    </tr>
                                )}
                                {companies.map((company) => (
                                    <tr
                                        key={company.id}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3">
                                            {company.company}
                                        </td>
                                        <td className="p-3">
                                            {company.environment ===
                                            'production'
                                                ? 'Produção'
                                                : 'Homologação'}
                                        </td>
                                        <td className="p-3">
                                            {company.is_registered
                                                ? 'Cadastrada'
                                                : 'Pendente'}
                                            {company.registration_error && (
                                                <span className="block text-xs text-destructive">
                                                    {company.registration_error}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {company.certificate_expires_at
                                                ? formatDateTime(
                                                      company.certificate_expires_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="p-3">
                                            {company.tax_settings_confirmed
                                                ? 'Sim'
                                                : 'Não'}
                                        </td>
                                        <td className="p-3">
                                            {company.documents}
                                        </td>
                                        <td className="p-3">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.patch(
                                                        release.url(company.id),
                                                        {
                                                            released:
                                                                company.production_released_at ===
                                                                null,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {company.production_released_at
                                                    ? 'Revogar produção'
                                                    : 'Liberar produção'}
                                            </Button>
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

RootAdminFiscal.layout = {
    breadcrumbs: [
        { title: 'RootAdmin', href: home() },
        { title: 'Documentos fiscais', href: index() },
    ],
};
