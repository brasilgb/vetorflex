<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\AccountStatus;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conta da empresa num provedor de pagamento. As credenciais (client_id, client_secret, certificado e senha) e o
 * token do webhook ficam criptografados (`encrypted`) e nunca são enviados às telas nem aos logs.
 *
 * @property int $id
 * @property int $company_id
 * @property string $provider
 * @property string $environment `homologation` ou `production`.
 * @property string $name
 * @property array{client_id?: string, client_secret?: string, certificate?: string, certificate_password?: string}|null $credentials
 * @property string|null $pix_key
 * @property list<string>|null $methods
 * @property int $expiration_seconds
 * @property string|null $webhook_token
 * @property string|null $webhook_token_hash
 * @property CarbonImmutable|null $webhook_registered_at
 * @property AccountStatus $status
 * @property CarbonImmutable|null $last_tested_at
 * @property string|null $last_error
 * @property int|null $finance_account_id
 * @property bool $is_active
 */
class PaymentProviderAccount extends Model
{
    protected $fillable = ['company_id', 'provider', 'environment', 'name', 'credentials', 'pix_key', 'methods', 'expiration_seconds', 'webhook_token', 'webhook_token_hash', 'webhook_registered_at', 'status', 'last_tested_at', 'last_error', 'finance_account_id', 'is_active'];

    protected $hidden = ['credentials', 'webhook_token', 'webhook_token_hash'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_token' => 'encrypted',
            'methods' => 'array',
            'status' => AccountStatus::class,
            'webhook_registered_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    /** @return list<string> Credenciais obrigatórias ainda não informadas. */
    public function missingCredentials(): array
    {
        $credentials = $this->credentials ?? [];

        return array_values(array_filter(['client_id', 'client_secret', 'certificate'], fn (string $key): bool => blank($credentials[$key] ?? null)));
    }
}
