<?php

namespace Database\Seeders;

use App\Modules\Customers\Enums\CustomerPermission;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Pos\Enums\PosPermission;
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
            ['identifier' => 'purchases', 'name' => 'Compras', 'description' => 'Fornecedores, pedidos de compra, recebimentos, custo médio e devoluções.', 'dependencies' => [], 'optional_integrations' => ['products', 'inventory', 'finance'], 'permissions' => PurchasePermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'sales', 'name' => 'Vendas', 'description' => 'Orçamentos, pedidos de venda, atendimentos, devoluções e indicadores comerciais.', 'dependencies' => [], 'optional_integrations' => ['customers', 'products', 'inventory', 'finance', 'pos'], 'permissions' => SalePermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'finance', 'name' => 'Financeiro', 'description' => 'Contas a pagar e a receber, baixas, contas financeiras, transferências, fluxo de caixa, conciliação e relatórios.', 'dependencies' => [], 'optional_integrations' => ['sales', 'purchases', 'pos'], 'permissions' => FinancePermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'pos', 'name' => 'PDV — Frente de Caixa', 'description' => 'Terminais, abertura e fechamento de caixa, venda rápida com pagamentos combinados, sangrias, suprimentos, devoluções e comprovantes não fiscais.', 'dependencies' => [], 'optional_integrations' => ['customers', 'products', 'inventory', 'sales', 'finance'], 'permissions' => PosPermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'inventory', 'name' => 'Estoque', 'description' => 'Mercadorias, saldos por depósito, entradas e saídas, transferências, inventários, alertas de estoque mínimo e relatórios.', 'dependencies' => [], 'optional_integrations' => ['products', 'purchases', 'sales', 'pos', 'finance'], 'permissions' => InventoryPermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
        ] as $sortOrder => $module) {
            // A disponibilidade comercial é decidida pelo RootAdmin e não pode ser desfeita por um novo seed.
            PlatformModule::query()->updateOrCreate(
                ['identifier' => $module['identifier']],
                ['development_status' => PlatformModule::DEVELOPMENT, ...$module, 'sort_order' => $sortOrder + 1],
            );
        }
    }
}
