<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Services\UnitOfMeasureProvisioner;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Itens comercializáveis dentro de Vendas, pelo catálogo compartilhado (`ProductCatalog`): busca e cadastro
 * simplificado. Não há catálogo paralelo, e o acesso às telas de Produtos continua exigindo aquele módulo.
 */
class SaleItems
{
    public function __construct(
        private ProductCatalog $catalog,
        private ProductService $products,
        private UnitOfMeasureProvisioner $units,
        private InventoryService $inventory,
        private SalesStock $stock,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(Company $company, string $term, bool $canViewCost): array
    {
        $variants = $this->catalog->searchSellableUnits($company, $term);
        $availability = $this->stock->availabilityOf($company, array_values($variants->filter(fn (ProductVariant $variant): bool => $variant->product->controlsStock())->map(fn (ProductVariant $variant): int => $variant->id)->all()));

        return array_values($variants->map(fn (ProductVariant $variant): array => $this->summary($company, $variant, $availability[$variant->id] ?? null, $canViewCost))->all());
    }

    /**
     * Sem o estoque habilitado, o item nasce sem controle de estoque, para não criar saldos ocultos.
     *
     * @param  array{name: string, type: string, sale_price: string, sku?: string|null, unit_code?: string|null}  $data
     */
    public function createSimplified(Company $company, User $user, array $data): ProductVariant
    {
        return DB::transaction(function () use ($company, $user, $data): ProductVariant {
            $type = ProductType::from($data['type']);
            $unit = $this->unit($company, $type, $data['unit_code'] ?? null);
            $product = $this->products->create($company, $user, [
                'type' => $type->value,
                'name' => $data['name'],
                'unit_of_measure_id' => $unit->id,
                'tracks_inventory' => $type !== ProductType::Service && $this->stock->isEnabled($company),
                'sku' => $data['sku'] ?? null,
                'generate_sku' => blank($data['sku'] ?? null),
                'sale_price' => $data['sale_price'],
                'notes' => 'Cadastro simplificado em Vendas.',
            ]);

            return $product->variants()->with('product.unit')->sole();
        });
    }

    /**
     * @param  array{quantity: string, reserved: string, available: string}|null  $availability
     * @return array<string, mixed>
     */
    public function summary(Company $company, ProductVariant $variant, ?array $availability, bool $canViewCost): array
    {
        $product = $variant->product;

        return [
            'id' => $variant->id,
            'name' => $variant->displayName(),
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'unit' => ['code' => $product->unit->code, 'allows_fraction' => $product->unit->allows_fraction],
            'price' => $this->catalog->pricesOf($variant)['effective'],
            'is_service' => $product->type === ProductType::Service,
            'controls_stock' => $product->controlsStock(),
            'available' => $availability['available'] ?? null,
            'average_cost' => $canViewCost ? $this->inventory->averageCostOf($company->id, $variant->id) : null,
        ];
    }

    private function unit(Company $company, ProductType $type, ?string $code): UnitOfMeasure
    {
        $default = $this->units->defaultFor($company, $type);

        if (blank($code)) {
            return $default;
        }

        return UnitOfMeasure::query()->where('company_id', $company->id)->where('is_active', true)->where('code', mb_strtoupper(trim($code)))->first() ?? $default;
    }
}
