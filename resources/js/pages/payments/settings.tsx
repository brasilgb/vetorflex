import { Head, router, useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { EnvironmentBadge } from '@/components/payments/charge-status';
import { paymentsBreadcrumb } from '@/components/payments/payments-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTenant } from '@/hooks/use-tenant';
import { settings } from '@/routes/payments';
import {
    webhook as registerWebhook,
    test,
    update,
} from '@/routes/payments/settings';
import type { NamedRecord, PaymentAccount } from '@/types';

type Provider = {
    identifier: string;
    name: string;
    capabilities: Record<string, boolean>;
    is_available: boolean;
};

const METHODS: [string, string][] = [
    ['pix', 'Pix'],
    ['boleto', 'Boleto'],
    ['credit_card', 'Cartão de crédito'],
];

function AccountForm({
    provider,
    environment,
    account,
    financeEnabled,
    financeAccounts,
}: {
    provider: Provider;
    environment: 'homologation' | 'production';
    account?: PaymentAccount;
    financeEnabled: boolean;
    financeAccounts: NamedRecord[];
}) {
    const form = useForm<{
        provider: string;
        environment: string;
        client_id: string;
        client_secret: string;
        certificate: File | null;
        certificate_password: string;
        pix_key: string;
        methods: string[];
        expiration_seconds: string;
        finance_account_id: string;
        is_active: boolean;
    }>({
        provider: provider.identifier,
        environment,
        client_id: '',
        client_secret: '',
        certificate: null,
        certificate_password: '',
        pix_key: account?.pix_key ?? '',
        methods: account?.methods ?? ['pix'],
        expiration_seconds: String(account?.expiration_seconds ?? 3600),
        finance_account_id: account?.finance_account_id
            ? String(account.finance_account_id)
            : '',
        is_active: account?.is_active ?? true,
    });
    const id = `${provider.identifier}-${environment}`;

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    finance_account_id:
                        data.finance_account_id === ''
                            ? null
                            : Number(data.finance_account_id),
                    expiration_seconds: Number(data.expiration_seconds),
                }));
                form.post(update.url(), {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () =>
                        form.reset(
                            'client_id',
                            'client_secret',
                            'certificate',
                            'certificate_password',
                        ),
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <FormField
                    id={`${id}-client-id`}
                    label="Client ID"
                    error={form.errors.client_id}
                    hint={
                        account?.has_client_id
                            ? `Informado (${account.client_id_hint}). Deixe em branco para manter.`
                            : undefined
                    }
                >
                    <Input
                        id={`${id}-client-id`}
                        autoComplete="off"
                        value={form.data.client_id}
                        onChange={(event) =>
                            form.setData('client_id', event.target.value)
                        }
                    />
                </FormField>
                <FormField
                    id={`${id}-client-secret`}
                    label="Client Secret"
                    error={form.errors.client_secret}
                    hint={
                        account?.has_client_secret
                            ? 'Informado. Deixe em branco para manter.'
                            : undefined
                    }
                >
                    <Input
                        id={`${id}-client-secret`}
                        type="password"
                        autoComplete="new-password"
                        value={form.data.client_secret}
                        onChange={(event) =>
                            form.setData('client_secret', event.target.value)
                        }
                    />
                </FormField>
                <FormField
                    id={`${id}-certificate`}
                    label="Certificado (.p12 ou .pem)"
                    error={form.errors.certificate}
                    hint={
                        account?.has_certificate
                            ? 'Certificado enviado. Envie outro para substituir.'
                            : 'Gerado na conta do provedor, um para cada ambiente.'
                    }
                >
                    <Input
                        id={`${id}-certificate`}
                        type="file"
                        accept=".p12,.pem"
                        onChange={(event) =>
                            form.setData(
                                'certificate',
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                </FormField>
                <FormField
                    id={`${id}-certificate-password`}
                    label="Senha do certificado (se houver)"
                    error={form.errors.certificate_password}
                >
                    <Input
                        id={`${id}-certificate-password`}
                        type="password"
                        autoComplete="new-password"
                        value={form.data.certificate_password}
                        onChange={(event) =>
                            form.setData(
                                'certificate_password',
                                event.target.value,
                            )
                        }
                    />
                </FormField>
                <FormField
                    id={`${id}-pix-key`}
                    label="Chave Pix da conta"
                    error={form.errors.pix_key}
                >
                    <Input
                        id={`${id}-pix-key`}
                        maxLength={120}
                        value={form.data.pix_key}
                        onChange={(event) =>
                            form.setData('pix_key', event.target.value)
                        }
                    />
                </FormField>
                <FormField
                    id={`${id}-expiration`}
                    label="Validade do Pix"
                    error={form.errors.expiration_seconds}
                >
                    <NativeSelect
                        id={`${id}-expiration`}
                        value={form.data.expiration_seconds}
                        onChange={(event) =>
                            form.setData(
                                'expiration_seconds',
                                event.target.value,
                            )
                        }
                    >
                        <option value="900">15 minutos</option>
                        <option value="3600">1 hora</option>
                        <option value="86400">1 dia</option>
                        <option value="259200">3 dias</option>
                        <option value="604800">7 dias</option>
                    </NativeSelect>
                </FormField>
                {financeEnabled && (
                    <FormField
                        id={`${id}-finance`}
                        label="Conta financeira que recebe (baixa automática)"
                        error={form.errors.finance_account_id}
                    >
                        <NativeSelect
                            id={`${id}-finance`}
                            value={form.data.finance_account_id}
                            onChange={(event) =>
                                form.setData(
                                    'finance_account_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Não baixar no Financeiro</option>
                            {financeAccounts.map((financeAccount) => (
                                <option
                                    key={financeAccount.id}
                                    value={financeAccount.id}
                                >
                                    {financeAccount.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                )}
            </div>
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">
                    Formas de pagamento
                </legend>
                <div className="flex flex-wrap gap-4">
                    {METHODS.map(([method, label]) => {
                        const supported =
                            provider.capabilities[method] === true;

                        return (
                            <CheckboxField
                                key={method}
                                id={`${id}-method-${method}`}
                                label={
                                    supported
                                        ? label
                                        : `${label} (não disponível na integração)`
                                }
                                checked={
                                    supported &&
                                    form.data.methods.includes(method)
                                }
                                disabled={!supported}
                                onChange={(checked) =>
                                    form.setData(
                                        'methods',
                                        checked
                                            ? [...form.data.methods, method]
                                            : form.data.methods.filter(
                                                  (value) => value !== method,
                                              ),
                                    )
                                }
                            />
                        );
                    })}
                </div>
            </fieldset>
            <CheckboxField
                id={`${id}-active`}
                label="Usar esta conta para emitir cobranças"
                checked={form.data.is_active}
                onChange={(checked) => form.setData('is_active', checked)}
            />
            {(form.errors as Record<string, string>).provider && (
                <p className="text-sm text-destructive">
                    {(form.errors as Record<string, string>).provider}
                </p>
            )}
            <Button disabled={form.processing}>Salvar</Button>
        </form>
    );
}

function AccountPanel({
    provider,
    environment,
    account,
    financeEnabled,
    financeAccounts,
}: {
    provider: Provider;
    environment: 'homologation' | 'production';
    account?: PaymentAccount;
    financeEnabled: boolean;
    financeAccounts: NamedRecord[];
}) {
    const { formatDateTime } = useTenant();
    const [copied, copy] = useClipboard();

    return (
        <section
            aria-label={`${provider.name} — ${environment}`}
            className="space-y-4 rounded-lg border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 font-medium">
                    {provider.name} —{' '}
                    {environment === 'production' ? 'Produção' : 'Homologação'}
                    <EnvironmentBadge environment={environment} />
                </h3>
                {account && (
                    <Badge
                        variant={
                            account.status === 'active'
                                ? 'default'
                                : account.status === 'failing'
                                  ? 'destructive'
                                  : 'outline'
                        }
                    >
                        {account.status_label}
                    </Badge>
                )}
            </div>
            {account?.last_error && (
                <p className="rounded-md border border-destructive/40 p-2 text-sm">
                    Última falha: {account.last_error}
                </p>
            )}
            {account?.last_tested_at && (
                <p className="text-xs text-muted-foreground">
                    Último teste: {formatDateTime(account.last_tested_at)}
                </p>
            )}
            <AccountForm
                provider={provider}
                environment={environment}
                account={account}
                financeEnabled={financeEnabled}
                financeAccounts={financeAccounts}
            />
            {account && (
                <div className="space-y-3 border-t pt-4">
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    test.url(account.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Testar conexão
                        </Button>
                        {provider.capabilities.webhooks && (
                            <Button
                                variant="outline"
                                disabled={account.status !== 'active'}
                                onClick={() =>
                                    router.post(
                                        registerWebhook.url(account.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {account.webhook_registered_at
                                    ? 'Registrar webhook novamente'
                                    : 'Registrar webhook'}
                            </Button>
                        )}
                    </div>
                    {provider.capabilities.webhooks && (
                        <div className="space-y-2 text-sm">
                            <p className="font-medium">
                                Webhook (notificação de pagamento)
                            </p>
                            <div className="flex gap-2">
                                <code className="flex-1 rounded bg-muted p-2 text-xs break-all">
                                    {account.webhook_url}
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => copy(account.webhook_url)}
                                >
                                    {copied === account.webhook_url
                                        ? 'Copiado'
                                        : 'Copiar'}
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {account.webhook_registered_at
                                    ? `Registrado em ${formatDateTime(account.webhook_registered_at)}.`
                                    : 'Ainda não registrado.'}{' '}
                                A URL é secreta: não a compartilhe. O registro
                                dispensa o mTLS no servidor da aplicação; a
                                origem é conferida pelo token da URL e cada
                                pagamento é confirmado por consulta à Efí. Mesmo
                                sem webhook, a conciliação consulta as cobranças
                                periodicamente.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

export default function PaymentSettings({
    accounts,
    providers,
    financeEnabled,
    financeAccounts,
}: {
    accounts: PaymentAccount[];
    providers: Provider[];
    financeEnabled: boolean;
    financeAccounts: NamedRecord[];
}) {
    return (
        <>
            <Head title="Configurações de pagamentos" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Configurações de pagamentos"
                    description="Os pagamentos caem diretamente na conta da sua empresa no provedor. As credenciais ficam criptografadas e nunca são exibidas depois de salvas."
                />
                <ol className="max-w-3xl list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                    <li>
                        Na conta Efí, crie uma aplicação com os escopos de
                        cobrança Pix, Pix, webhook e localização de payload.
                    </li>
                    <li>
                        Gere o certificado do ambiente (homologação ou produção)
                        e anote Client ID e Client Secret.
                    </li>
                    <li>
                        Informe os dados abaixo, salve e use “Testar conexão”.
                        Comece pela homologação: nela nenhum dinheiro real é
                        movimentado.
                    </li>
                    <li>
                        Com a conexão confirmada, registre o webhook para
                        receber as confirmações de pagamento automaticamente.
                    </li>
                </ol>
                {providers.map((provider) =>
                    provider.is_available ? (
                        <div
                            key={provider.identifier}
                            className="grid gap-4 xl:grid-cols-2"
                        >
                            {(['homologation', 'production'] as const).map(
                                (environment) => (
                                    <AccountPanel
                                        key={environment}
                                        provider={provider}
                                        environment={environment}
                                        account={accounts.find(
                                            (account) =>
                                                account.provider ===
                                                    provider.identifier &&
                                                account.environment ===
                                                    environment,
                                        )}
                                        financeEnabled={financeEnabled}
                                        financeAccounts={financeAccounts}
                                    />
                                ),
                            )}
                        </div>
                    ) : (
                        <p
                            key={provider.identifier}
                            className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                        >
                            {provider.name} ainda não está disponível para
                            contratação na plataforma. Fale com o suporte.
                        </p>
                    ),
                )}
            </div>
        </>
    );
}

PaymentSettings.layout = {
    breadcrumbs: [
        paymentsBreadcrumb,
        { title: 'Configurações', href: settings() },
    ],
};
