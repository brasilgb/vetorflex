<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Services\InventoryReservationService;
use App\Modules\Inventory\Services\StockModule;
use App\Modules\Tenancy\Models\Company;

/**
 * Integração opcional com o estoque. O controle de estoque é ativado pelo módulo Estoque ou por Produtos
 * (`StockModule`); sem nenhum deles habilitado, Vendas e PDV não criam reservas nem movimentações, nem mesmo ocultas.
 */
class SalesStock
{
    public function __construct(private StockModule $stock, private InventoryReservationService $reservations) {}

    public function isEnabled(Company $company): bool
    {
        return $this->stock->isEnabled($company);
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
