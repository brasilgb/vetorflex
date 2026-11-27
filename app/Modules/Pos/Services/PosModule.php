<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Pos\Data\PosSettings;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Habilitação do PDV e dos módulos com que ele se integra opcionalmente, e as configurações da empresa.
 */
class PosModule
{
    public const IDENTIFIER = 'pos';

    public function __construct(private TenantContext $tenants, private AuditService $audit) {}

    public function isEnabled(Company $company, string $identifier = self::IDENTIFIER): bool
    {
        return $this->tenants->enabledModulesFor($company)->contains('identifier', $identifier);
    }

    public function settings(Company $company): PosSettings
    {
        return PosSettings::from($company->settings['pos'] ?? null);
    }

    public function saveSettings(Company $company, User $user, PosSettings $settings): void
    {
        $company->update(['settings' => [...($company->settings ?? []), 'pos' => $settings->toArray()]]);
        $this->audit->record('pos.settings_updated', $company, $user, $company);
    }
}
