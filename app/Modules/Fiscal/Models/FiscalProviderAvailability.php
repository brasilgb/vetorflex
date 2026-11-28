<?php

namespace App\Modules\Fiscal\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Provedor fiscal na plataforma: disponibilidade e tipos de documento liberados pelo RootAdmin, ambiente da conta
 * da plataforma e credenciais da plataforma (chave da empresa titular e segredo do webhook), criptografadas e nunca
 * enviadas às telas.
 *
 * @property int $id
 * @property string $identifier
 * @property string $name
 * @property bool $is_available
 * @property list<string>|null $document_types
 * @property string $environment `sandbox` ou `production`.
 * @property array{owner_api_key?: string, webhook_secret?: string}|null $platform_credentials
 * @property CarbonImmutable|null $webhook_registered_at
 * @property string|null $last_error
 */
class FiscalProviderAvailability extends Model
{
    protected $table = 'fiscal_providers';

    protected $fillable = ['identifier', 'name', 'is_available', 'document_types', 'environment', 'platform_credentials', 'webhook_registered_at', 'last_error'];

    protected $hidden = ['platform_credentials'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'document_types' => 'array', 'platform_credentials' => 'encrypted:array', 'webhook_registered_at' => 'datetime'];
    }

    public function ownerKey(): ?string
    {
        $key = $this->platform_credentials['owner_api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function webhookSecret(): ?string
    {
        $secret = $this->platform_credentials['webhook_secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
