<?php

namespace App\Modules\Products\Services;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Indicadores operacionais do catálogo. O valor do estoque é uma estimativa pelo custo cadastrado.
 */
class ProductIndicators
{
    /** @return array<string, int|string|null> */
    public function for(Company $company, bool $canViewInventory, bool $canViewCost): array
    {
        $indicators = [
            'active' => $this->products($company)->where('is_active', true)->count(),
            'inactive' => $this->products($company)->where('is_active', false)->count(),
        ];

        if ($canViewInventory) {
            $indicators += [
                'controlled' => $this->products($company)->where('is_active', true)->controllingStock()->count(),
                'low' => $this->products($company)->where('is_active', true)->belowMinimumStock()->count(),
                'out' => $this->products($company)->where('is_active', true)->outOfStock()->count(),
            ];
        }

        if ($canViewInventory && $canViewCost) {
            $indicators += [
                'stock_value' => $this->stockValue($company),
                'stock_without_cost' => $this->stocked($company)->whereNull('product_variants.cost_price')->count(),
            ];
        }

        return $indicators;
    }

    private function stockValue(Company $company): string
    {
        $total = $this->stocked($company)->whereNotNull('product_variants.cost_price')
            ->selectRaw('SUM(inventory_balances.quantity * product_variants.cost_price) as total')
            ->toBase()->value('total');

        return (string) BigDecimal::of((string) ($total ?? '0'))->toScale(2, RoundingMode::HalfUp);
    }

    /** @return Builder<InventoryBalance> */
    private function stocked(Company $company): Builder
    {
        return InventoryBalance::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_balances.product_variant_id')
            ->where('inventory_balances.company_id', $company->id)
            ->where('inventory_balances.quantity', '>', 0);
    }

    /** @return Builder<Product> */
    private function products(Company $company): Builder
    {
        return Product::query()->where('products.company_id', $company->id);
    }
}
