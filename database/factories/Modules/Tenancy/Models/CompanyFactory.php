<?php

namespace Database\Factories\Modules\Tenancy\Models;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'status' => 'active',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
            'settings' => [],
        ];
    }
}
