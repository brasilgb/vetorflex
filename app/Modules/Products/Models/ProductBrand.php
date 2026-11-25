<?php

namespace App\Modules\Products\Models;

use Database\Factories\Modules\Products\Models\ProductBrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property bool $is_active
 */
class ProductBrand extends Model
{
    /** @use HasFactory<ProductBrandFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected static function newFactory(): ProductBrandFactory
    {
        return ProductBrandFactory::new();
    }
}
