<?php

namespace Database\Factories\Modules\Products\Models;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'company_id' => fn (array $attributes) => Product::query()->whereKey($attributes['product_id'])->value('company_id'),
            'is_default' => true,
            'sale_price' => '10.00',
        ];
    }
}
