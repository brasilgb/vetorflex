<?php

namespace Database\Factories\Modules\Products\Models;

use App\Modules\Products\Models\ProductBrand;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBrand>
 */
class ProductBrandFactory extends Factory
{
    protected $model = ProductBrand::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
