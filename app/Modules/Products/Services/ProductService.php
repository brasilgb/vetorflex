<?php

namespace App\Modules\Products\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPriceChange;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Support\Pricing;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    private const PRODUCT_FIELDS = ['name', 'short_description', 'description', 'internal_code', 'product_category_id', 'product_brand_id', 'unit_of_measure_id', 'manufacturer_reference', 'tracks_inventory', 'ncm', 'cest', 'merchandise_origin', 'notes'];

    private const VARIANT_FIELDS = ['sku', 'barcode', 'barcode_type', 'cost_price', 'sale_price', 'promotional_price', 'minimum_stock', 'weight', 'height', 'width', 'length'];

    public function __construct(
        private AuditService $audit,
        private InventoryService $inventory,
        private UnitOfMeasureProvisioner $units,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, User $user, array $data): Product
    {
        return DB::transaction(function () use ($company, $user, $data): Product {
            $type = ProductType::from($data['type']);
            $product = new Product([...Arr::only($data, self::PRODUCT_FIELDS), 'company_id' => $company->id, 'type' => $type, 'created_by' => $user->id]);
            $product->unit_of_measure_id ??= $this->units->defaultFor($company, $type)->id;
            $product->tracks_inventory = $type !== ProductType::Service && ($data['tracks_inventory'] ?? true);
            $product->save();

            if ($type !== ProductType::Variable) {
                $variant = $this->saveVariant(new ProductVariant(['company_id' => $company->id, 'product_id' => $product->id, 'is_default' => true]), $data, $user);

                if (filled($data['initial_stock'] ?? null) && $product->controlsStock()) {
                    $this->inventory->recordInitial(new StockOperation(
                        companyId: $company->id,
                        variantId: $variant->id,
                        quantity: (string) $data['initial_stock'],
                        origin: MovementOrigin::Product,
                        user: $user,
                        reason: 'Estoque inicial informado no cadastro.',
                    ));
                }
            }

            $this->audit->record('product.created', $company, $user, $product, ['type' => $type->value]);

            return $product;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, User $user, array $data): Product
    {
        return DB::transaction(function () use ($product, $user, $data): Product {
            $variantIds = ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->lockForUpdate()->pluck('id');
            $product->refresh()->fill(Arr::only($data, self::PRODUCT_FIELDS));

            if ($product->type === ProductType::Service) {
                $product->tracks_inventory = false;
            }

            $this->ensureStockRulesArePreserved($product, $variantIds);
            $changes = array_keys($product->getDirty());
            $product->save();

            if ($product->type !== ProductType::Variable) {
                $this->saveVariant($product->variants()->where('is_default', true)->firstOrFail(), $data, $user);
            }

            $this->audit->record('product.updated', $product->company, $user, $product, ['fields' => $changes]);

            return $product;
        });
    }

    public function setActive(Product $product, User $user, bool $isActive): void
    {
        $product->update(['is_active' => $isActive]);
        $this->audit->record($isActive ? 'product.reactivated' : 'product.deactivated', $product->company, $user, $product);
    }

    /**
     * Todas as gravações de preço passam por aqui, para que o histórico seja completo.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveVariant(ProductVariant $variant, array $data, ?User $user): ProductVariant
    {
        return DB::transaction(function () use ($variant, $data, $user): ProductVariant {
            $variant = $variant->exists ? ProductVariant::query()->lockForUpdate()->findOrFail($variant->id) : $variant;
            $previous = Arr::only($variant->exists ? $variant->attributesToArray() : [], ProductPriceChange::FIELDS);
            $variant->fill(Arr::only($data, [...self::VARIANT_FIELDS, 'is_active']));
            $variant->minimum_stock = (string) ($data['minimum_stock'] ?? ($variant->exists && ! array_key_exists('minimum_stock', $data) ? $variant->minimum_stock : '0'));
            $variant->barcode_type = $variant->barcode === null ? null : $variant->barcode_type;
            $variant->save();

            if (($data['generate_sku'] ?? false) && $variant->sku === null) {
                $variant->sku = $this->availableSku($variant);
                $variant->save();
            }

            foreach (ProductPriceChange::FIELDS as $field) {
                $old = $previous[$field] ?? null;
                $new = $variant->getAttribute($field);

                if (! Pricing::equals($old, $new)) {
                    $variant->priceChanges()->create([
                        'company_id' => $variant->company_id,
                        'field' => $field,
                        'old_value' => $old,
                        'new_value' => $new,
                        'user_id' => $user?->id,
                        'user_name' => $user?->name,
                        'changed_at' => now(),
                    ]);
                }
            }

            return $variant;
        });
    }

    private function availableSku(ProductVariant $variant): string
    {
        $base = sprintf('P%06d-%d', $variant->product_id, $variant->id);
        $sku = $base;

        for ($suffix = 1; ProductVariant::query()->where('company_id', $variant->company_id)->where('sku', $sku)->exists(); $suffix++) {
            $sku = "{$base}-{$suffix}";
        }

        return $sku;
    }

    /** @param Collection<int, int> $variantIds */
    private function ensureStockRulesArePreserved(Product $product, Collection $variantIds): void
    {
        if ($product->isDirty('tracks_inventory') && ! $product->tracks_inventory && InventoryBalance::query()->whereIn('product_variant_id', $variantIds)->where('quantity', '!=', 0)->exists()) {
            throw ValidationException::withMessages(['tracks_inventory' => 'Zere o saldo antes de desabilitar o controle de estoque.']);
        }

        if ($product->isDirty('unit_of_measure_id') && $product->variants()->whereHas('movements')->exists()) {
            throw ValidationException::withMessages(['unit_of_measure_id' => 'A unidade não pode ser alterada após movimentações de estoque.']);
        }
    }
}
