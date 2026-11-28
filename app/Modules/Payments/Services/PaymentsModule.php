<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\AccountStatus;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Providers\ProviderRegistry;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Habilitação do módulo e conta de provedor em uso pela empresa.
 */
class PaymentsModule
{
    public const IDENTIFIER = 'payments';

    public function __construct(private TenantContext $tenants, private ProviderRegistry $providers) {}

    public function isEnabled(Company $company, string $identifier = self::IDENTIFIER): bool
    {
        return $this->tenants->enabledModulesFor($company)->contains('identifier', $identifier);
    }

    /** Conta ativa, conectada e de provedor disponível na plataforma, apta a emitir cobranças. */
    public function activeAccount(Company $company): ?PaymentProviderAccount
    {
        $account = PaymentProviderAccount::query()->where('company_id', $company->id)->where('is_active', true)->where('status', AccountStatus::Active)
            ->orderByRaw("case when environment = 'production' then 0 else 1 end")->first();

        return $account !== null && $this->providers->isAvailable($account->provider) ? $account : null;
    }

    /** Pix confirmado pelo provedor disponível para a empresa (PDV e telas de cobrança). */
    public function hasProviderPix(Company $company): bool
    {
        $account = $this->isEnabled($company) ? $this->activeAccount($company) : null;

        return $account !== null && $this->providers->get($account->provider)->capabilities()->pix && in_array('pix', $account->methods ?? ['pix'], true);
    }
}
