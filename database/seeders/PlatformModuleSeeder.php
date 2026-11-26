<?php

namespace Database\Seeders;

use App\Modules\Customers\Enums\CustomerPermission;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Sales\Enums\SalePermission;
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
            ['identifier' => 'purchases', 'name' => 'Compras', 'description' => 'Fornecedores, pedidos de compra, recebimentos, custo médio e devoluções.', 'dependencies' => ['products'], 'optional_integrations' => ['finance'], 'permissions' => PurchasePermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'sales', 'name' => 'Vendas', 'description' => 'Orçamentos, pedidos de venda, atendimentos, devoluções e indicadores comerciais.', 'dependencies' => [], 'optional_integrations' => ['customers', 'products', 'finance'], 'permissions' => SalePermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'finance', 'name' => 'Financeiro', 'description' => 'Contas a pagar e a receber, baixas, contas financeiras, transferências, fluxo de caixa, conciliação e relatórios.', 'dependencies' => [], 'optional_integrations' => ['sales', 'purchases'], 'permissions' => FinancePermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
        ] as $sortOrder => $module) {
            // A disponibilidade comercial é decidida pelo RootAdmin e não pode ser desfeita por um novo seed.
            PlatformModule::query()->updateOrCreate(
                ['identifier' => $module['identifier']],
                ['development_status' => PlatformModule::DEVELOPMENT, ...$module, 'sort_order' => $sortOrder + 1],
            );
        }
    }
}
