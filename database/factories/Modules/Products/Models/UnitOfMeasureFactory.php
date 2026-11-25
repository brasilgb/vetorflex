<?php

namespace Database\Factories\Modules\Products\Models;

use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitOfMeasure>
 */
class UnitOfMeasureFactory extends Factory
{
    protected $model = UnitOfMeasure::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->lexify('????'),
            'name' => fake()->word(),
            'allows_fraction' => false,
        ];
    }

    public function fractional(): static
    {
        return $this->state(['allows_fraction' => true]);
    }
}
