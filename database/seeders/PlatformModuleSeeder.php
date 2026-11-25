<?php

namespace Database\Seeders;

use App\Modules\Customers\Enums\CustomerPermission;
use App\Modules\ModuleManager\Models\PlatformModule;
use Illuminate\Database\Seeder;

class PlatformModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([
            ['identifier' => 'customers', 'name' => 'Clientes', 'description' => 'Base de clientes da empresa.', 'dependencies' => [], 'permissions' => CustomerPermission::catalog()],
            ['identifier' => 'products', 'name' => 'Produtos', 'description' => 'Catálogo de produtos e serviços.', 'dependencies' => []],
            ['identifier' => 'purchases', 'name' => 'Compras', 'description' => 'Fluxo de compras.', 'dependencies' => ['products']],
            ['identifier' => 'sales', 'name' => 'Vendas', 'description' => 'Fluxo de vendas.', 'dependencies' => ['customers', 'products']],
            ['identifier' => 'finance', 'name' => 'Financeiro', 'description' => 'Gestão financeira independente.', 'dependencies' => []],
        ] as $sortOrder => $module) {
            PlatformModule::query()->updateOrCreate(
                ['identifier' => $module['identifier']],
                [...$module, 'sort_order' => $sortOrder + 1, 'development_status' => 'development', 'commercially_available' => false],
            );
        }
    }
}
