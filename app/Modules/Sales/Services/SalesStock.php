<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Services\InventoryReservationService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Integração opcional com o estoque. O núcleo de estoque pertence ao módulo Produtos; sem ele habilitado, Vendas não
 * cria reservas nem movimentações, nem mesmo ocultas.
 */
class SalesStock
{
    public const MODULE = 'products';

    public function __construct(private TenantContext $tenants, private InventoryReservationService $reservations) {}

    public function isEnabled(Company $company): bool
    {
        return $this->tenants->enabledModulesFor($company)->contains('identifier', self::MODULE);
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<int, array{quantity: string, reserved: string, available: string}>
     */
    public function availabilityOf(Company $company, array $variantIds): array
    {
        return $this->isEnabled($company) && $variantIds !== [] ? $this->reservations->availabilityOf($company->id, $variantIds) : [];
    }
}
