<?php

namespace Database\Seeders;

use App\Modules\Customers\Enums\CustomerPermission;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Products\Enums\ProductPermission;
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
            ['identifier' => 'products', 'name' => 'Produtos', 'description' => 'Catálogo de produtos e serviços.', 'dependencies' => [], 'permissions' => ProductPermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'purchases', 'name' => 'Compras', 'description' => 'Fluxo de compras.', 'dependencies' => ['products']],
            ['identifier' => 'sales', 'name' => 'Vendas', 'description' => 'Fluxo de vendas.', 'dependencies' => ['customers', 'products']],
            ['identifier' => 'finance', 'name' => 'Financeiro', 'description' => 'Gestão financeira independente.', 'dependencies' => []],
        ] as $sortOrder => $module) {
            // A disponibilidade comercial é decidida pelo RootAdmin e não pode ser desfeita por um novo seed.
            PlatformModule::query()->updateOrCreate(
                ['identifier' => $module['identifier']],
                ['development_status' => PlatformModule::DEVELOPMENT, ...$module, 'sort_order' => $sortOrder + 1],
            );
        }
    }
}
