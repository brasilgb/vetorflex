<?php

namespace App\Modules\Products\Support;

use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Services\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

/**
 * Monta os dados enviados à interface, omitindo custos e saldos de quem não tem permissão.
 */
class ProductPresenter
{
    public function __construct(private TenantContext $context) {}

    /** @return array<string, mixed> */
    public function product(Product $product): array
    {
        return [
            ...$product->only(['id', 'name', 'short_description', 'description', 'internal_code', 'product_category_id', 'product_brand_id', 'unit_of_measure_id', 'manufacturer_reference', 'tracks_inventory', 'ncm', 'cest', 'merchandise_origin', 'notes', 'is_active']),
            'type' => $product->type->value,
            'type_label' => $product->type->label(),
            'category' => $product->category?->name,
            'brand' => $product->brand?->name,
            'unit' => $product->unit->only(['code', 'name', 'allows_fraction']),
            'controls_stock' => $product->controlsStock(),
        ];
    }

    /** @return array<string, mixed> */
    public function variant(Request $request, ProductVariant $variant, Product $product): array
    {
        $data = [
            ...$variant->only(['id', 'label', 'sku', 'barcode', 'sale_price', 'promotional_price', 'minimum_stock', 'weight', 'height', 'width', 'length', 'is_default', 'is_active']),
            'barcode_type' => $variant->barcode_type?->value,
        ];

        if ($this->context->allows($request, ProductPermission::ViewCost->value)) {
            $effective = Pricing::effective($variant->sale_price, $variant->promotional_price);
            $data['cost_price'] = $variant->cost_price;
            $data['margin'] = Pricing::margin($variant->cost_price, $effective);
            $data['markup'] = Pricing::markup($variant->cost_price, $effective);
            $data['average_cost'] = $product->controlsStock() ? $variant->balance?->average_cost : null;
        }

        if ($product->controlsStock() && $this->context->allows($request, ProductPermission::ViewInventory->value)) {
            $balance = $variant->balance->quantity ?? '0.0000';
            $data['balance'] = $balance;
            $data['is_low_stock'] = BigDecimal::of($balance)->isLessThan($variant->minimum_stock);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function image(ProductImage $image): array
    {
        return [
            ...$image->only(['id', 'product_variant_id', 'original_name', 'is_primary']),
            'url' => route('products.images.show', [$image->product_id, $image->id]),
        ];
    }

    /** @return array<string, mixed> */
    public function movement(InventoryMovement $movement): array
    {
        return [
            ...$movement->only(['id', 'product_variant_id', 'quantity', 'balance_before', 'balance_after', 'reference_type', 'reference_id', 'reversal_of_id', 'user_name', 'reason']),
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'origin_label' => $movement->origin->label(),
            'occurred_at' => $movement->occurred_at->toIso8601String(),
            'item' => $movement->variant->displayName(),
            'product_id' => $movement->variant->product_id,
            'sku' => $movement->variant->sku,
            'unit' => $movement->variant->product->unit->code,
            'is_reversed' => $movement->reversal !== null,
            'is_reversible' => $movement->type !== MovementType::Reversal && $movement->reversal === null && $movement->origin !== MovementOrigin::Purchase,
        ];
    }
}
