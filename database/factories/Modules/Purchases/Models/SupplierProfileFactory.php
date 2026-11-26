<?php

namespace Database\Factories\Modules\Purchases\Models;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierProfile>
 */
class SupplierProfileFactory extends Factory
{
    protected $model = SupplierProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'party_id' => fn (array $attributes) => Party::factory()->create(['company_id' => $attributes['company_id']])->id,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SupplierProfile $supplier): void {
            $supplier->party->roles()->firstOrCreate(['role' => PartyRole::SUPPLIER], ['company_id' => $supplier->company_id]);
        });
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
