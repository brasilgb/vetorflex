<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Payments\Enums\AccountStatus;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Providers\ProviderException;
use App\Modules\Payments\Providers\ProviderRegistry;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configuração da conta da empresa num provedor. Segredos em branco mantêm os valores gravados; nunca são devolvidos
 * às telas. Alterar credenciais exige novo teste de conexão. A URL do webhook leva um token aleatório próprio da conta,
 * que identifica a empresa sem depender de dados enviados pelo provedor.
 */
class ProviderAccountService
{
    public function __construct(private ProviderRegistry $providers, private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function save(Company $company, User $user, array $data, ?UploadedFile $certificate): PaymentProviderAccount
    {
        $provider = $this->providers->get((string) $data['provider']);

        if (! $this->providers->isAvailable($provider->identifier())) {
            throw ValidationException::withMessages(['provider' => "{$provider->name()} não está disponível na plataforma."]);
        }

        $account = PaymentProviderAccount::query()->firstOrNew(['company_id' => $company->id, 'provider' => $provider->identifier(), 'environment' => $data['environment']]);
        $credentials = $account->credentials ?? [];
        $changed = false;

        foreach (['client_id', 'client_secret', 'certificate_password'] as $key) {
            if (filled($data[$key] ?? null)) {
                $changed = $changed || ($credentials[$key] ?? null) !== $data[$key];
                $credentials[$key] = (string) $data[$key];
            }
        }

        if ($certificate !== null) {
            $credentials['certificate'] = base64_encode((string) $certificate->getContent());
            $changed = true;
        }

        $methods = array_values(array_intersect($data['methods'] ?? ['pix'], $provider->capabilities()->methods()));
        $account->fill([
            'name' => $data['name'] ?? "{$provider->name()} — ".($data['environment'] === 'production' ? 'Produção' : 'Homologação'),
            'credentials' => $credentials,
            'pix_key' => $data['pix_key'] ?? null,
            'methods' => $methods === [] ? ['pix'] : $methods,
            'expiration_seconds' => (int) ($data['expiration_seconds'] ?? 3600),
            'finance_account_id' => $data['finance_account_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if ($account->webhook_token === null) {
            $token = Str::random(48);
            $account->webhook_token = $token;
            $account->webhook_token_hash = hash('sha256', $token);
        }

        if (! $account->exists || $changed || $account->isDirty('pix_key') || $account->isDirty('environment')) {
            $account->status = $account->missingCredentials() === [] && filled($account->pix_key) ? AccountStatus::Untested : AccountStatus::Incomplete;
        }

        $account->save();
        $this->audit->record('payments.provider_configured', $company, $user, $account, ['provider' => $account->provider, 'environment' => $account->environment, 'credentials_changed' => $changed]);

        return $account;
    }

    public function test(PaymentProviderAccount $account, User $user): PaymentProviderAccount
    {
        if ($account->missingCredentials() !== [] || blank($account->pix_key)) {
            throw ValidationException::withMessages(['account' => 'Informe Client ID, Client Secret, certificado e chave Pix antes de testar.']);
        }

        try {
            $this->providers->get($account->provider)->testConnection($account);
            $account->update(['status' => AccountStatus::Active, 'last_tested_at' => now(), 'last_error' => null]);
        } catch (ProviderException $exception) {
            $account->update(['status' => AccountStatus::Failing, 'last_tested_at' => now(), 'last_error' => Str::limit($exception->getMessage(), 490)]);
        }

        $this->audit->record('payments.provider_tested', Company::query()->findOrFail($account->company_id), $user, $account, ['status' => $account->status->value]);

        return $account;
    }

    public function registerWebhook(PaymentProviderAccount $account, User $user): PaymentProviderAccount
    {
        if ($account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages(['account' => 'Teste a conexão com sucesso antes de registrar o webhook.']);
        }

        try {
            $this->providers->get($account->provider)->registerWebhook($account, $this->webhookUrl($account));
        } catch (ProviderException $exception) {
            $account->update(['last_error' => Str::limit($exception->getMessage(), 490)]);

            throw ValidationException::withMessages(['account' => $exception->getMessage()]);
        }

        $account->update(['webhook_registered_at' => now(), 'last_error' => null]);
        $this->audit->record('payments.webhook_registered', Company::query()->findOrFail($account->company_id), $user, $account, ['provider' => $account->provider]);

        return $account;
    }

    /** URL de notificação da conta. Na Efí, `?ignorar=` evita o sufixo `/pix` (a rota aceita os dois formatos). */
    public function webhookUrl(PaymentProviderAccount $account): string
    {
        return rtrim((string) config('app.url'), '/')."/webhooks/payments/{$account->provider}/{$account->webhook_token}?ignorar=";
    }
}
