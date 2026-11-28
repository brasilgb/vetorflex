<?php

namespace App\Modules\Finance\Services;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Habilitação do Financeiro por empresa. Integrações só agem quando ele está ativo; desativá-lo não apaga dados.
 */
class FinanceModule
{
    public const IDENTIFIER = 'finance';

    public function __construct(private TenantContext $tenants) {}

    public function isEnabled(Company $company): bool
    {
        return $this->tenants->enabledModulesFor($company)->contains('identifier', self::IDENTIFIER);
    }
}
