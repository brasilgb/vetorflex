<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Services\UnitOfMeasureProvisioner;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mercadorias do módulo Estoque: a mesma entidade de produto e unidade comercializável usada por Produtos, Compras,
 * Vendas e PDV (`ProductService`). O cadastro simplificado cria um produto simples com controle de estoque; o saldo
 * inicial vai para o depósito escolhido.
 *
 * @phpstan-type ItemFilters array{search?: string|null, category?: int|null, situation?: string|null, warehouse?: int|null}
 */
class StockItems
{
    public function __construct(
        private ProductService $products,
        private InventoryService $inventory,
        private UnitOfMeasureProvisioner $units,
        private StockModule $module,
        private AuditService $audit,
    ) {}

    /**
     * Unidades com controle de estoque, com saldo total (`balance`), reservado (`reserved`) e, se pedido, saldo no
     * depósito (`warehouse_quantity`).
     *
     * @param  ItemFilters  $filters
     * @return Builder<ProductVariant>
     */
    public function query(Company $company, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $warehouse = $filters['warehouse'] ?? null;

        return ProductVariant::query()
            ->select('product_variants.*')
            ->selectRaw('coalesce(inventory_balances.quantity, 0) as balance, coalesce(inventory_balances.reserved_quantity, 0) as reserved')
            ->leftJoin('inventory_balances', 'inventory_balances.product_variant_id', '=', 'product_variants.id')
            ->when($warehouse !== null, fn (Builder $query) => $query
                ->leftJoin('inventory_warehouse_balances', fn ($join) => $join->on('inventory_warehouse_balances.product_variant_id', '=', 'product_variants.id')->where('inventory_warehouse_balances.inventory_warehouse_id', $warehouse))
                ->selectRaw('coalesce(inventory_warehouse_balances.quantity, 0) as warehouse_quantity'))
            ->where('product_variants.company_id', $company->id)
            ->whereHas('product', fn (Builder $product) => $product->where('type', '!=', ProductType::Service)->where('tracks_inventory', true)
                ->when($filters['category'] ?? null, fn (Builder $query, int $category) => $query->where('product_category_id', $category)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('product_variants.sku', 'like', "%{$search}%")
                ->orWhere('product_variants.barcode', Str::upper($search))
                ->orWhere('product_variants.label', 'like', "%{$search}%")
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$search}%")->orWhere('internal_code', $search))))
            ->when($filters['situation'] ?? null, fn (Builder $query, string $situation) => match ($situation) {
                'low' => $query->where('product_variants.minimum_stock', '>', 0)->whereRaw('coalesce(inventory_balances.quantity, 0) < product_variants.minimum_stock'),
                'above_max' => $query->whereNotNull('product_variants.maximum_stock')->whereRaw('coalesce(inventory_balances.quantity, 0) > product_variants.maximum_stock'),
                'zero' => $query->whereRaw('coalesce(inventory_balances.quantity, 0) <= 0'),
                'with_balance' => $query->whereRaw('coalesce(inventory_balances.quantity, 0) > 0'),
                'inactive' => $query->where(fn (Builder $query) => $query->where('product_variants.is_active', false)->orWhereHas('product', fn (Builder $product) => $product->where('is_active', false))),
                default => $query,
            })
            ->with(['product.unit', 'product.category']);
    }

    /**
     * Cadastro simplificado: nome, códigos, categoria (existente ou nova), unidade, estoque mínimo e máximo, custo,
     * preço de venda opcional e saldo inicial no depósito escolhido.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, User $user, array $data): ProductVariant
    {
        return DB::transaction(function () use ($company, $user, $data): ProductVariant {
            $this->assertThresholds($data['minimum_stock'] ?? '0', $data['maximum_stock'] ?? null);
            $product = $this->products->create($company, $user, [
                'type' => ProductType::Simple->value,
                'name' => $data['name'],
                'internal_code' => $data['internal_code'] ?? null,
                'product_category_id' => $this->categoryId($company, $data),
                'unit_of_measure_id' => $this->unitId($company, $data['unit_code'] ?? null),
                'tracks_inventory' => true,
                'sku' => filled($data['sku'] ?? null) ? Str::upper((string) $data['sku']) : null,
                'generate_sku' => blank($data['sku'] ?? null),
                'barcode' => filled($data['barcode'] ?? null) ? Str::upper((string) $data['barcode']) : null,
                'barcode_type' => filled($data['barcode'] ?? null) ? 'internal' : null,
                'cost_price' => $data['cost_price'] ?? null,
                'sale_price' => $data['sale_price'] ?? null,
                'minimum_stock' => $data['minimum_stock'] ?? '0',
                'maximum_stock' => $data['maximum_stock'] ?? null,
                'notes' => 'Cadastro simplificado no Estoque.',
            ]);
            $variant = $product->variants()->with('product.unit')->sole();

            if (filled($data['initial_stock'] ?? null) && BigDecimal::of((string) $data['initial_stock'])->isPositive()) {
                $this->inventory->recordInitial(new StockOperation(
                    companyId: $company->id,
                    variantId: $variant->id,
                    quantity: (string) $data['initial_stock'],
                    origin: MovementOrigin::Manual,
                    user: $user,
                    reason: 'Saldo inicial informado no cadastro da mercadoria.',
                    unitCost: $data['cost_price'] ?? null,
                    warehouseId: isset($data['inventory_warehouse_id']) ? (int) $data['inventory_warehouse_id'] : null,
                ));
            }

            $this->module->ensureStarted($company, $user);

            return $variant;
        });
    }

    /** Estoque mínimo e máximo da unidade. */
    public function updateThresholds(ProductVariant $variant, User $user, string $minimum, ?string $maximum): ProductVariant
    {
        $this->assertThresholds($minimum, $maximum);
        $variant = $this->products->saveVariant($variant, ['minimum_stock' => $minimum, 'maximum_stock' => $maximum], $user);
        $this->audit->record('inventory.thresholds_updated', $variant->product->company, $user, $variant, ['minimum' => $minimum, 'maximum' => $maximum]);

        return $variant;
    }

    /**
     * Data da última movimentação de cada unidade, para "sem movimentação".
     *
     * @param  list<int>  $variantIds
     * @return array<int, string>
     */
    public function lastMovementDates(int $companyId, array $variantIds): array
    {
        return InventoryMovement::query()->where('company_id', $companyId)->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')->selectRaw('product_variant_id, max(occurred_at) as last_at')->pluck('last_at', 'product_variant_id')
            ->map(fn (mixed $value): string => (string) $value)->all();
    }

    private function assertThresholds(string $minimum, ?string $maximum): void
    {
        if ($maximum !== null && $maximum !== '' && BigDecimal::of($maximum)->isLessThan($minimum)) {
            throw ValidationException::withMessages(['maximum_stock' => 'O estoque máximo deve ser maior ou igual ao mínimo.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function categoryId(Company $company, array $data): ?int
    {
        if (filled($data['product_category_id'] ?? null)) {
            return ProductCategory::query()->where('company_id', $company->id)->findOrFail((int) $data['product_category_id'])->id;
        }

        if (filled($data['category_name'] ?? null)) {
            return ProductCategory::query()->firstOrCreate(['company_id' => $company->id, 'parent_id' => null, 'name' => trim((string) $data['category_name'])], ['is_active' => true])->id;
        }

        return null;
    }

    private function unitId(Company $company, ?string $code): int
    {
        $default = $this->units->defaultFor($company, ProductType::Simple);

        if (blank($code)) {
            return $default->id;
        }

        return UnitOfMeasure::query()->where('company_id', $company->id)->where('code', Str::upper($code))->where('is_active', true)->value('id')
            ?? throw ValidationException::withMessages(['unit_code' => "Unidade {$code} não encontrada."]);
    }
}
