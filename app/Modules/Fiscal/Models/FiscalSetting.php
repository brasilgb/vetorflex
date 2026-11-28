<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuração fiscal da empresa. A chave da empresa emissora no provedor e o CSC da NFC-e ficam criptografados e
 * ocultos; o certificado digital vai direto ao provedor e só titular e validade ficam aqui.
 *
 * @property int $id
 * @property int $company_id
 * @property string|null $legal_name
 * @property string|null $trade_name
 * @property string|null $cnpj
 * @property string|null $state_registration
 * @property string|null $municipal_registration
 * @property string|null $email
 * @property string|null $phone
 * @property array{street?: string, number?: string, district?: string, postal_code?: string, complement?: string|null, city?: string, state?: string}|null $address
 * @property string|null $city_code
 * @property string|null $tax_regime
 * @property string|null $provider
 * @property string $environment `homologation` ou `production`.
 * @property list<string>|null $enabled_types
 * @property string|null $provider_company_id
 * @property array{api_key?: string}|null $provider_credentials
 * @property string|null $provider_environment
 * @property string $registration_status
 * @property string|null $registration_error
 * @property CarbonImmutable|null $registered_at
 * @property string|null $certificate_subject
 * @property CarbonImmutable|null $certificate_expires_at
 * @property array<string, string>|null $series
 * @property array{service_code?: string|null, iss_rate?: string|null, taxation_type?: string|null, city_code?: string|null, issue_type?: string|null}|null $nfse
 * @property array{unit?: string|null, tax_unit?: string|null, origin?: string|null, icms_situation?: string|null, pis_situation?: string|null, cofins_situation?: string|null, cfop?: string|null}|null $product_defaults
 * @property string|null $nfce_csc
 * @property string|null $nfce_csc_id
 * @property CarbonImmutable|null $tax_settings_confirmed_at
 * @property string|null $tax_settings_confirmed_by
 * @property CarbonImmutable|null $production_released_at
 * @property string|null $production_released_by
 * @property-read Company|null $company
 */
class FiscalSetting extends Model
{
    public const REGISTRATION_PENDING = 'pending';

    public const REGISTRATION_REGISTERED = 'registered';

    public const REGISTRATION_ERROR = 'error';

    protected $fillable = [
        'company_id', 'legal_name', 'trade_name', 'cnpj', 'state_registration', 'municipal_registration', 'email', 'phone', 'address', 'city_code',
        'tax_regime', 'provider', 'environment', 'enabled_types', 'provider_company_id', 'provider_credentials', 'provider_environment',
        'registration_status', 'registration_error', 'registered_at', 'certificate_subject', 'certificate_expires_at', 'series', 'nfse',
        'product_defaults', 'nfce_csc', 'nfce_csc_id', 'tax_settings_confirmed_at', 'tax_settings_confirmed_by', 'production_released_at', 'production_released_by',
    ];

    protected $hidden = ['provider_credentials', 'nfce_csc'];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'enabled_types' => 'array',
            'provider_credentials' => 'encrypted:array',
            'series' => 'array',
            'nfse' => 'array',
            'product_defaults' => 'array',
            'nfce_csc' => 'encrypted',
            'registered_at' => 'datetime',
            'certificate_expires_at' => 'datetime',
            'tax_settings_confirmed_at' => 'datetime',
            'production_released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function apiKey(): ?string
    {
        $key = $this->provider_credentials['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function isRegistered(): bool
    {
        return $this->registration_status === self::REGISTRATION_REGISTERED && filled($this->provider_company_id) && $this->apiKey() !== null;
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function hasValidCertificate(): bool
    {
        return $this->certificate_expires_at !== null && $this->certificate_expires_at->isFuture();
    }

    public function enables(DocumentType $type): bool
    {
        return in_array($type->value, $this->enabled_types ?? [], true);
    }

    public function seriesFor(DocumentType $type): string
    {
        return (string) ($this->series[$type->value] ?? '1');
    }
}
