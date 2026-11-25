<?php

namespace App\Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $position
 * @property bool $is_primary
 */
class ProductImage extends Model
{
    protected $fillable = ['company_id', 'product_id', 'product_variant_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'width', 'height', 'position', 'is_primary', 'uploaded_by'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
