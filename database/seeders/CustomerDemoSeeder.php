<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Customers\Models\CustomerProfile;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CustomerDemoSeeder extends Seeder
{
    /**
     * A conta de demonstração tem senha conhecida; por isso só existe em ambientes local e de teste.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('CustomerDemoSeeder ignorado: disponível apenas nos ambientes local e testing.');

            return;
        }

        $user = User::query()->firstOrCreate(['email' => 'demo@vetornew.test'], [
            'name' => 'Usuário Demonstração',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $company = Company::query()->firstOrCreate(['slug' => 'empresa-demonstracao'], [
            'name' => 'Empresa Demonstração',
            'status' => 'active',
            'timezone' => 'America/Sao_Paulo',
            'currency' => 'BRL',
        ]);
        $user->companies()->syncWithoutDetaching([$company->id => ['role' => 'owner', 'is_active' => true]]);

        $module = PlatformModule::query()->where('identifier', 'customers')->firstOrFail();
        CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);

        foreach ([
            ['name' => 'Ana Souza', 'type' => 'individual', 'document' => '52998224725', 'email' => 'ana@example.test'],
            ['name' => 'Comercial Horizonte Ltda.', 'type' => 'company', 'document' => '11222333000181', 'email' => 'contato@horizonte.example.test'],
        ] as $attributes) {
            $party = Party::query()->firstOrCreate(['company_id' => $company->id, 'document' => $attributes['document']], $attributes + ['company_id' => $company->id]);
            $party->roles()->firstOrCreate(['role' => PartyRole::CUSTOMER], ['company_id' => $company->id]);
            CustomerProfile::query()->firstOrCreate(['party_id' => $party->id], ['company_id' => $company->id, 'registered_by' => $user->id]);
        }
    }
}
