<?php

namespace App\Modules\Products\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string|null $user_name
 * @property CarbonImmutable $changed_at
 * @property-read ProductVariant $variant
 */
class ProductPriceChange extends Model
{
    public const FIELDS = ['cost_price', 'sale_price', 'promotional_price'];

    public $timestamps = false;

    protected $fillable = ['company_id', 'product_variant_id', 'field', 'old_value', 'new_value', 'user_id', 'user_name', 'changed_at'];

    protected function casts(): array
    {
        return ['old_value' => 'decimal:4', 'new_value' => 'decimal:4', 'changed_at' => 'datetime'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
