<?php

namespace Database\Factories\Modules\Products\Models;

use App\Modules\Products\Models\ProductCategory;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

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
