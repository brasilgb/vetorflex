import { Head, router, useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import { fiscalBreadcrumb } from '@/components/fiscal/fiscal-nav';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { parseDecimal } from '@/lib/decimal';
import { settings as settingsRoute } from '@/routes/fiscal';
import {
    certificate,
    confirm,
    register,
    update,
} from '@/routes/fiscal/settings';
import type { FiscalAddress, FiscalReadiness, Option } from '@/types';

type Settings = {
    legal_name: string | null;
    trade_name: string | null;
    cnpj: string | null;
    state_registration: string | null;
    municipal_registration: string | null;
    email: string | null;
    phone: string | null;
    address: FiscalAddress;
    city_code: string | null;
    tax_regime: string | null;
    environment: string;
    enabled_types: string[];
    series: Record<string, string>;
    nfse: Record<string, string | null>;
    product_defaults: Record<string, string | null>;
    nfce_csc_id: string | null;
    has_nfce_csc: boolean;
    registration_status: string;
    registration_error: string | null;
    is_registered: boolean;
    registered_at: string | null;
    certificate_subject: string | null;
    certificate_expires_at: string | null;
    has_valid_certificate: boolean;
    tax_settings_confirmed_at: string | null;
    tax_settings_confirmed_by: string | null;
    production_released_at: string | null;
};

function CertificateForm({ hasCertificate }: { hasCertificate: boolean }) {
    const form = useForm<{ certificate: File | null; password: string }>({
        certificate: null,
        password: '',
    });

    return (
        <form
            className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(certificate.url(), {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <FormField
                id="certificate-file"
                label="Certificado A1 (.pfx)"
                hint={
                    hasCertificate
                        ? 'Envie outro para substituir.'
                        : 'Enviado direto ao provedor; o arquivo e a senha não ficam gravados.'
                }
                error={form.errors.certificate}
            >
                <Input
                    id="certificate-file"
                    type="file"
                    accept=".pfx,.p12"
                    onChange={(event) =>
                        form.setData(
                            'certificate',
                            event.target.files?.[0] ?? null,
                        )
                    }
                />
            </FormField>
            <FormField
                id="certificate-password"
                label="Senha do certificado"
                error={form.errors.password}
            >
                <Input
                    id="certificate-password"
                    type="password"
                    autoComplete="new-password"
                    value={form.data.password}
                    onChange={(event) =>
                        form.setData('password', event.target.value)
                    }
                />
            </FormField>
            <div className="flex items-end">
                <Button
                    disabled={form.processing || form.data.certificate === null}
                >
                    Enviar certificado
                </Button>
            </div>
        </form>
    );
}

export default function FiscalSettings({
    settings,
    types,
    platform,
    readiness,
    taxRegimes,
    taxationTypes,
}: {
    settings: Settings;
    types: Option[];
    platform: {
        available: boolean;
        environment: string | null;
        types: string[];
        provider: string | null;
    };
    readiness: FiscalReadiness[];
    taxRegimes: Option[];
    taxationTypes: Option[];
}) {
    const { formatDateTime } = useTenant();
    const form = useForm({
        legal_name: settings.legal_name ?? '',
        trade_name: settings.trade_name ?? '',
        cnpj: settings.cnpj ?? '',
        state_registration: settings.state_registration ?? '',
        municipal_registration: settings.municipal_registration ?? '',
        email: settings.email ?? '',
        phone: settings.phone ?? '',
        address: {
            postal_code: settings.address.postal_code ?? '',
            street: settings.address.street ?? '',
            number: settings.address.number ?? '',
            complement: settings.address.complement ?? '',
            district: settings.address.district ?? '',
            city: settings.address.city ?? '',
            state: settings.address.state ?? '',
        },
        city_code: settings.city_code ?? '',
        tax_regime: settings.tax_regime ?? '',
        environment: settings.environment,
        enabled_types: settings.enabled_types,
        series: {
            nfse: settings.series.nfse ?? '1',
            nfe: settings.series.nfe ?? '1',
            nfce: settings.series.nfce ?? '1',
        },
        nfse: {
            service_code: settings.nfse.service_code ?? '',
            iss_rate: settings.nfse.iss_rate ?? '',
            taxation_type: settings.nfse.taxation_type ?? '',
            issue_type: settings.nfse.issue_type ?? 'annfs',
        },
        product_defaults: {
            unit: settings.product_defaults.unit ?? '',
            tax_unit: settings.product_defaults.tax_unit ?? '',
            origin: settings.product_defaults.origin ?? '',
            cfop: settings.product_defaults.cfop ?? '',
            icms_situation: settings.product_defaults.icms_situation ?? '',
            pis_situation: settings.product_defaults.pis_situation ?? '',
            cofins_situation: settings.product_defaults.cofins_situation ?? '',
        },
        nfce_csc_id: settings.nfce_csc_id ?? '',
        nfce_csc: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const text = (
        id: string,
        label: string,
        value: string,
        onChange: (value: string) => void,
        hint?: string,
        type = 'text',
    ) => (
        <FormField
            key={id}
            id={id}
            label={label}
            hint={hint}
            error={errors[id]}
        >
            <Input
                id={id}
                type={type}
                autoComplete={type === 'password' ? 'new-password' : undefined}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormField>
    );
    const nested = (
        group: 'address' | 'series' | 'nfse' | 'product_defaults',
        field: string,
        value: string,
    ) =>
        form.setData((data) => ({
            ...data,
            [group]: { ...data[group], [field]: value },
        }));

    return (
        <>
            <Head title="Configurações fiscais" />
            <div className="space-y-8 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Configurações fiscais"
                    description="Dados do emitente, regime e padrões tributários. Os códigos fiscais devem ser conferidos pelo contador: nada é preenchido por aproximação."
                />

                {!platform.available && (
                    <p className="rounded-lg border border-amber-500/50 p-3 text-sm">
                        A emissão fiscal ainda não foi disponibilizada pela
                        plataforma. É possível preparar a configuração e os
                        rascunhos.
                    </p>
                )}

                <section
                    aria-label="Prontidão"
                    className="grid gap-3 md:grid-cols-3"
                >
                    {readiness.map((item) => (
                        <div
                            key={item.type}
                            className="rounded-lg border p-3 text-sm"
                        >
                            <p className="font-medium">{item.label}</p>
                            {item.blockers.length === 0 ? (
                                <p className="text-emerald-700 dark:text-emerald-400">
                                    Pronto para emitir.
                                </p>
                            ) : (
                                <ul className="list-disc pl-4 text-muted-foreground">
                                    {item.blockers.map((blocker) => (
                                        <li key={blocker}>{blocker}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    ))}
                </section>

                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            nfse: {
                                ...data.nfse,
                                iss_rate: data.nfse.iss_rate
                                    ? parseDecimal(data.nfse.iss_rate)
                                    : null,
                                service_code: data.nfse.service_code || null,
                                taxation_type: data.nfse.taxation_type || null,
                            },
                            product_defaults: Object.fromEntries(
                                Object.entries(data.product_defaults).map(
                                    ([key, value]) => [key, value || null],
                                ),
                            ),
                        }));
                        form.post(update.url(), {
                            preserveScroll: true,
                            onSuccess: () => form.setData('nfce_csc', ''),
                        });
                    }}
                >
                    <section className="space-y-3">
                        <h3 className="font-medium">Emitente</h3>
                        <div className="grid gap-4 md:grid-cols-3">
                            {text(
                                'legal_name',
                                'Razão social (até 80 caracteres)',
                                form.data.legal_name,
                                (value) => form.setData('legal_name', value),
                            )}
                            {text(
                                'trade_name',
                                'Nome fantasia',
                                form.data.trade_name,
                                (value) => form.setData('trade_name', value),
                            )}
                            {text('cnpj', 'CNPJ', form.data.cnpj, (value) =>
                                form.setData('cnpj', value),
                            )}
                            {text(
                                'state_registration',
                                'Inscrição estadual',
                                form.data.state_registration,
                                (value) =>
                                    form.setData('state_registration', value),
                            )}
                            {text(
                                'municipal_registration',
                                'Inscrição municipal',
                                form.data.municipal_registration,
                                (value) =>
                                    form.setData(
                                        'municipal_registration',
                                        value,
                                    ),
                            )}
                            {text(
                                'email',
                                'E-mail fiscal',
                                form.data.email,
                                (value) => form.setData('email', value),
                            )}
                            {text(
                                'phone',
                                'Telefone',
                                form.data.phone,
                                (value) => form.setData('phone', value),
                            )}
                            {(
                                [
                                    ['postal_code', 'CEP'],
                                    ['street', 'Logradouro'],
                                    ['number', 'Número'],
                                    ['complement', 'Complemento'],
                                    ['district', 'Bairro'],
                                    ['city', 'Cidade'],
                                    ['state', 'UF'],
                                ] as const
                            ).map(([field, label]) =>
                                text(
                                    `address.${field}`,
                                    label,
                                    form.data.address[field],
                                    (value) => nested('address', field, value),
                                ),
                            )}
                            {text(
                                'city_code',
                                'Código IBGE do município',
                                form.data.city_code,
                                (value) => form.setData('city_code', value),
                                '7 dígitos',
                            )}
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">Emissão</h3>
                        <div className="grid gap-4 md:grid-cols-3">
                            <FormField
                                id="tax_regime"
                                label="Regime tributário"
                                error={errors.tax_regime}
                            >
                                <NativeSelect
                                    id="tax_regime"
                                    value={form.data.tax_regime}
                                    onChange={(event) =>
                                        form.setData(
                                            'tax_regime',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Selecione</option>
                                    {taxRegimes.map((regime) => (
                                        <option
                                            key={regime.value}
                                            value={regime.value}
                                        >
                                            {regime.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            <FormField
                                id="environment"
                                label="Ambiente"
                                hint="Produção depende de liberação da plataforma após a homologação."
                                error={errors.environment}
                            >
                                <NativeSelect
                                    id="environment"
                                    value={form.data.environment}
                                    onChange={(event) =>
                                        form.setData(
                                            'environment',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="homologation">
                                        Homologação (sem validade fiscal)
                                    </option>
                                    <option value="production">Produção</option>
                                </NativeSelect>
                            </FormField>
                        </div>
                        <div className="space-y-2">
                            {types.map((type) => (
                                <div
                                    key={type.value}
                                    className="flex flex-wrap items-center gap-4"
                                >
                                    <CheckboxField
                                        id={`type-${type.value}`}
                                        label={type.label}
                                        disabled={
                                            !platform.types.includes(type.value)
                                        }
                                        checked={form.data.enabled_types.includes(
                                            type.value,
                                        )}
                                        onChange={(checked) =>
                                            form.setData(
                                                'enabled_types',
                                                checked
                                                    ? [
                                                          ...form.data
                                                              .enabled_types,
                                                          type.value,
                                                      ]
                                                    : form.data.enabled_types.filter(
                                                          (value) =>
                                                              value !==
                                                              type.value,
                                                      ),
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label={`Série ${type.label}`}
                                        className="w-24"
                                        value={
                                            form.data.series[
                                                type.value as
                                                    | 'nfse'
                                                    | 'nfe'
                                                    | 'nfce'
                                            ]
                                        }
                                        onChange={(event) =>
                                            nested(
                                                'series',
                                                type.value,
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {!platform.types.includes(type.value) && (
                                        <span className="text-xs text-muted-foreground">
                                            Não liberado na plataforma.
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">Padrões da NFS-e</h3>
                        <div className="grid gap-4 md:grid-cols-4">
                            {text(
                                'nfse.service_code',
                                'Item da lista de serviços (LC 116)',
                                form.data.nfse.service_code,
                                (value) =>
                                    nested('nfse', 'service_code', value),
                                'Ex.: 01.07',
                            )}
                            {text(
                                'nfse.iss_rate',
                                'Alíquota de ISS (%)',
                                form.data.nfse.iss_rate,
                                (value) => nested('nfse', 'iss_rate', value),
                                'De 0 a 5',
                            )}
                            <FormField
                                id="nfse.taxation_type"
                                label="Tipo de tributação"
                                error={errors['nfse.taxation_type']}
                            >
                                <NativeSelect
                                    id="nfse.taxation_type"
                                    value={form.data.nfse.taxation_type}
                                    onChange={(event) =>
                                        nested(
                                            'nfse',
                                            'taxation_type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Selecione</option>
                                    {taxationTypes.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            <FormField
                                id="nfse.issue_type"
                                label="Padrão de emissão"
                                error={errors['nfse.issue_type']}
                            >
                                <NativeSelect
                                    id="nfse.issue_type"
                                    value={form.data.nfse.issue_type}
                                    onChange={(event) =>
                                        nested(
                                            'nfse',
                                            'issue_type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="annfs">
                                        NFS-e Nacional (ADN)
                                    </option>
                                    <option value="normal">
                                        Padrão do município
                                    </option>
                                </NativeSelect>
                            </FormField>
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">
                            Padrões de mercadorias (NF-e e NFC-e)
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Usados quando o item não tem o dado próprio. NCM é
                            sempre do item.
                        </p>
                        <div className="grid gap-4 md:grid-cols-4">
                            {(
                                [
                                    ['unit', 'Unidade comercial', 'Ex.: UN'],
                                    [
                                        'tax_unit',
                                        'Unidade tributável',
                                        'Em branco: igual à comercial',
                                    ],
                                    ['origin', 'Origem da mercadoria', '0 a 8'],
                                    ['cfop', 'CFOP de venda interna', '5xxx'],
                                    [
                                        'icms_situation',
                                        'CST/CSOSN do ICMS',
                                        'Simples: CSOSN de 3 dígitos',
                                    ],
                                    [
                                        'pis_situation',
                                        'CST do PIS',
                                        '2 dígitos',
                                    ],
                                    [
                                        'cofins_situation',
                                        'CST do COFINS',
                                        '2 dígitos',
                                    ],
                                ] as const
                            ).map(([field, label, hint]) =>
                                text(
                                    `product_defaults.${field}`,
                                    label,
                                    String(
                                        form.data.product_defaults[field] ?? '',
                                    ),
                                    (value) =>
                                        nested(
                                            'product_defaults',
                                            field,
                                            value,
                                        ),
                                    hint,
                                ),
                            )}
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">NFC-e</h3>
                        <div className="grid gap-4 md:grid-cols-3">
                            {text(
                                'nfce_csc_id',
                                'ID do CSC',
                                form.data.nfce_csc_id,
                                (value) => form.setData('nfce_csc_id', value),
                                'Fornecido pela SEFAZ',
                            )}
                            {text(
                                'nfce_csc',
                                'CSC',
                                form.data.nfce_csc,
                                (value) => form.setData('nfce_csc', value),
                                settings.has_nfce_csc
                                    ? 'Informado. Deixe em branco para manter.'
                                    : 'Guardado criptografado.',
                                'password',
                            )}
                        </div>
                    </section>

                    <Button disabled={form.processing}>
                        Salvar configuração
                    </Button>
                </form>

                <section
                    aria-label="Confirmação tributária"
                    className="space-y-2 rounded-lg border p-4"
                >
                    <h3 className="font-medium">
                        Confirmação dos dados tributários
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {settings.tax_settings_confirmed_at
                            ? `Confirmado por ${settings.tax_settings_confirmed_by} em ${formatDateTime(settings.tax_settings_confirmed_at)}. Alterar regime ou padrões exige nova confirmação.`
                            : 'Confirme que regime, códigos e alíquotas foram revisados (preferencialmente pelo contador). Sem confirmação, a emissão fica bloqueada.'}
                    </p>
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                confirm.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Confirmar dados tributários
                    </Button>
                </section>

                <section
                    aria-label="Provedor fiscal"
                    className="space-y-3 rounded-lg border p-4"
                >
                    <h3 className="font-medium">
                        Cadastro no provedor{' '}
                        {platform.provider ? `(${platform.provider})` : ''}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {settings.is_registered
                            ? `Empresa cadastrada como emissora${settings.registered_at ? ` em ${formatDateTime(settings.registered_at)}` : ''}. Salve a configuração e cadastre de novo para atualizar os dados no provedor.`
                            : 'Salve a configuração e cadastre a empresa como emissora.'}
                    </p>
                    {settings.registration_error && (
                        <p className="text-sm text-destructive">
                            {settings.registration_error}
                        </p>
                    )}
                    {errors.provider && (
                        <p className="text-sm text-destructive">
                            {errors.provider}
                        </p>
                    )}
                    <Button
                        variant="outline"
                        disabled={!platform.available}
                        onClick={() =>
                            router.post(
                                register.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {settings.is_registered
                            ? 'Atualizar cadastro no provedor'
                            : 'Cadastrar empresa no provedor'}
                    </Button>
                    <div className="space-y-2 border-t pt-3">
                        <p className="text-sm">
                            Certificado:{' '}
                            {settings.certificate_expires_at
                                ? `${settings.certificate_subject ?? 'A1'} — válido até ${formatDateTime(settings.certificate_expires_at)}${settings.has_valid_certificate ? '' : ' (vencido)'}`
                                : 'não enviado'}
                        </p>
                        {settings.is_registered && (
                            <CertificateForm
                                hasCertificate={
                                    settings.certificate_expires_at !== null
                                }
                            />
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

FiscalSettings.layout = {
    breadcrumbs: [
        fiscalBreadcrumb,
        { title: 'Configurações', href: settingsRoute() },
    ],
};
