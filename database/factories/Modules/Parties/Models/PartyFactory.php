<?php

namespace Database\Factories\Modules\Parties\Models;

use App\Modules\Parties\Models\Party;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => 'company',
            'name' => fake()->company(),
            'email' => fake()->safeEmail(),
            'is_active' => true,
        ];
    }
}
