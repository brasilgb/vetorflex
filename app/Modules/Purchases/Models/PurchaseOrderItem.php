<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Descrição, SKU e unidade são cópias do cadastro no momento do pedido.
 *
 * @property int $id
 * @property int $company_id
 * @property int $purchase_order_id
 * @property int $product_variant_id
 * @property int $position
 * @property string $description
 * @property string|null $sku
 * @property string|null $supplier_code
 * @property string $unit_code
 * @property bool $allows_fraction
 * @property bool $is_service
 * @property bool $is_free
 * @property string $quantity
 * @property string $unit_cost
 * @property string $discount
 * @property string $total
 * @property string|null $acquisition_total
 * @property string $received_quantity
 * @property-read ProductVariant $variant
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = ['company_id', 'purchase_order_id', 'product_variant_id', 'position', 'description', 'sku', 'supplier_code', 'unit_code', 'allows_fraction', 'is_service', 'is_free', 'quantity', 'unit_cost', 'discount', 'total', 'acquisition_total', 'received_quantity'];

    protected $attributes = ['received_quantity' => '0'];

    protected function casts(): array
    {
        return [
            'allows_fraction' => 'boolean',
            'is_service' => 'boolean',
            'is_free' => 'boolean',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'acquisition_total' => 'decimal:2',
            'received_quantity' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
