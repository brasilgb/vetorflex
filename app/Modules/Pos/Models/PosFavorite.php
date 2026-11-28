<?php

namespace App\Modules\Pos\Models;

use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atalho para uma unidade comercializável do catálogo compartilhado.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_variant_id
 * @property int $position
 * @property-read ProductVariant $variant
 */
class PosFavorite extends Model
{
    protected $fillable = ['company_id', 'product_variant_id', 'position'];

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
