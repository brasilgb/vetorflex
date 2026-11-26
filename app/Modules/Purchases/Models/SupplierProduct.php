<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Products\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $supplier_profile_id
 * @property int $product_variant_id
 * @property string|null $supplier_code
 * @property string|null $reference
 * @property string|null $last_cost
 * @property CarbonImmutable|null $last_purchased_at
 * @property int|null $lead_time_days
 * @property string|null $minimum_quantity
 * @property bool $is_preferred
 * @property string|null $notes
 * @property-read ProductVariant $variant
 * @property-read SupplierProfile $supplier
 */
class SupplierProduct extends Model
{
    protected $fillable = ['company_id', 'supplier_profile_id', 'product_variant_id', 'supplier_code', 'reference', 'last_cost', 'last_purchased_at', 'lead_time_days', 'minimum_quantity', 'is_preferred', 'notes'];

    protected $attributes = ['is_preferred' => false];

    protected function casts(): array
    {
        return ['last_cost' => 'decimal:4', 'last_purchased_at' => 'date', 'minimum_quantity' => 'decimal:4', 'is_preferred' => 'boolean', 'lead_time_days' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<SupplierProfile, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierProfile::class, 'supplier_profile_id');
    }
}
