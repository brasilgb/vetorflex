<?php

namespace Database\Factories\Modules\Products\Models;

use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => ProductType::Simple,
            'name' => fake()->words(3, true),
            'unit_of_measure_id' => fn (array $attributes) => UnitOfMeasure::factory()->create(['company_id' => $attributes['company_id']])->id,
        ];
    }

    public function variable(): static
    {
        return $this->state(['type' => ProductType::Variable]);
    }

    public function service(): static
    {
        return $this->state(['type' => ProductType::Service, 'tracks_inventory' => false]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
