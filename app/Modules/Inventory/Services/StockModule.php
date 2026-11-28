<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Habilitação e configuração do controle de estoque da empresa. O núcleo `Inventory` é compartilhado; o controle fica
 * ativo com o módulo Estoque (`inventory`) ou com Produtos (`products`, que já controlava estoque antes do Estoque).
 * Sem nenhum dos dois, nenhum módulo cria reservas ou movimentações, nem ocultas.
 *
 * Configurações em `companies.settings['inventory']`: `allow_negative` (padrão: recusado) e `started_at` (data de
 * início do controle, registrada na primeira configuração ou na ativação posterior).
 *
 * @phpstan-type StockSettings array{allow_negative: bool, started_at: string|null}
 */
class StockModule
{
    public const IDENTIFIER = 'inventory';

    /** Módulos que ativam o controle de estoque. */
    public const STOCK_MODULES = [self::IDENTIFIER, 'products'];

    public function __construct(private TenantContext $tenants, private AuditService $audit) {}

    public function isEnabled(Company $company): bool
    {
        return $this->tenants->enabledModulesFor($company)->whereIn('identifier', self::STOCK_MODULES)->isNotEmpty();
    }

    public function hasModule(Company $company, string $identifier): bool
    {
        return $this->tenants->enabledModulesFor($company)->contains('identifier', $identifier);
    }

    /** @return StockSettings */
    public function settings(Company $company): array
    {
        $values = $company->settings['inventory'] ?? [];

        return ['allow_negative' => (bool) ($values['allow_negative'] ?? false), 'started_at' => $values['started_at'] ?? null];
    }

    /** Lido dentro da trava de `InventoryService`; a recusa é o padrão. */
    public function allowsNegative(int $companyId): bool
    {
        $settings = Company::query()->whereKey($companyId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;

        return (bool) ($settings['inventory']['allow_negative'] ?? false);
    }

    /** @param StockSettings $settings */
    public function saveSettings(Company $company, User $user, array $settings): void
    {
        $company->update(['settings' => [...($company->settings ?? []), 'inventory' => $settings]]);
        $this->audit->record('inventory.settings_updated', $company, $user, $company, $settings);
    }

    /** Registra a data de início do controle de estoque, uma única vez. */
    public function ensureStarted(Company $company, User $user, ?string $date = null): void
    {
        $settings = $this->settings($company);

        if ($settings['started_at'] === null) {
            $this->saveSettings($company, $user, [...$settings, 'started_at' => $date ?? now($company->timezone)->format('Y-m-d')]);
        }
    }
}
