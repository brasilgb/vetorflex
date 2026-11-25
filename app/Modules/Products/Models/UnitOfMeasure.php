<?php

namespace App\Modules\Products\Models;

use Database\Factories\Modules\Products\Models\UnitOfMeasureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property bool $allows_fraction
 * @property bool $is_default
 * @property bool $is_active
 */
class UnitOfMeasure extends Model
{
    /** @use HasFactory<UnitOfMeasureFactory> */
    use HasFactory;

    protected $table = 'units_of_measure';

    protected $fillable = ['company_id', 'code', 'name', 'allows_fraction', 'is_default', 'is_active'];

    protected $attributes = ['allows_fraction' => false, 'is_default' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['allows_fraction' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected static function newFactory(): UnitOfMeasureFactory
    {
        return UnitOfMeasureFactory::new();
    }
}
