<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Habilitação do módulo Documentos Fiscais e de módulos integrados opcionais.
 */
class FiscalModule
{
    public const IDENTIFIER = 'fiscal';

    public function __construct(private TenantContext $tenants) {}

    public function isEnabled(Company $company, string $identifier = self::IDENTIFIER): bool
    {
        return $this->tenants->enabledModulesFor($company)->contains('identifier', $identifier);
    }

    public function settings(Company $company): FiscalSetting
    {
        return FiscalSetting::query()->firstOrCreate(['company_id' => $company->id], [
            'trade_name' => mb_substr($company->name, 0, 80),
            'environment' => 'homologation',
            'enabled_types' => [],
            'series' => ['nfse' => '1', 'nfe' => '1', 'nfce' => '1'],
        ]);
    }
}
