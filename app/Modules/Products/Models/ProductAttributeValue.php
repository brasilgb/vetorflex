<?php

namespace App\Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_attribute_id
 * @property string $value
 * @property-read ProductAttribute $attribute
 */
class ProductAttributeValue extends Model
{
    protected $fillable = ['company_id', 'product_attribute_id', 'value'];

    /** @return BelongsTo<ProductAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }
}
