<?php

namespace Database\Seeders;

use App\Modules\Customers\Enums\CustomerPermission;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Fiscal\Enums\FiscalPermission;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Payments\Enums\PaymentPermission;
use App\Modules\Payments\Providers\ProviderRegistry;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Sales\Enums\SalePermission;
use Illuminate\Database\Seeder;

class PlatformModuleSeeder extends Seeder
{
    /**
     * Textos iniciais do cardápio (editáveis pelo RootAdmin). Somente o que cada módulo faz hoje; sem valores comerciais.
     *
     * @var array<string, array{tagline: string, benefits: list<string>, icon: string, category: string}>
     */
    private const COMMERCIAL = [
        'customers' => ['tagline' => 'Cadastro único dos seus clientes.', 'benefits' => ['Clientes pessoa física e jurídica', 'Busca por nome e documento', 'Importação e exportação em CSV'], 'icon' => 'users', 'category' => 'Relacionamento'],
        'products' => ['tagline' => 'Catálogo de produtos e serviços.', 'benefits' => ['Variações, código de barras e imagens', 'Preço de venda, promoção e custo', 'Importação por planilha'], 'icon' => 'package', 'category' => 'Catálogo'],
        'purchases' => ['tagline' => 'Compras, fornecedores e custo médio.', 'benefits' => ['Pedidos de compra e recebimentos', 'Cadastro de fornecedores', 'Custo médio atualizado no recebimento'], 'icon' => 'shopping-cart', 'category' => 'Operação'],
        'sales' => ['tagline' => 'Orçamentos, vendas e devoluções.', 'benefits' => ['Orçamento que vira venda', 'Entregas, retiradas e devoluções', 'Indicadores de vendas'], 'icon' => 'receipt', 'category' => 'Vendas'],
        'finance' => ['tagline' => 'Contas a pagar, a receber e fluxo de caixa.', 'benefits' => ['Baixas, parcelas e recorrências', 'Fluxo de caixa e conciliação', 'Relatórios por categoria'], 'icon' => 'wallet', 'category' => 'Financeiro'],
        'pos' => ['tagline' => 'Frente de caixa rápida para o balcão.', 'benefits' => ['Abertura e fechamento de caixa', 'Pagamentos combinados e troco', 'Sangrias, suprimentos e comprovante não fiscal'], 'icon' => 'store', 'category' => 'Vendas'],
        'inventory' => ['tagline' => 'Saldos por depósito, sempre corretos.', 'benefits' => ['Entradas, saídas e transferências', 'Inventário com contagem', 'Alertas de estoque mínimo'], 'icon' => 'warehouse', 'category' => 'Operação'],
        'payments' => ['tagline' => 'Receba por Pix na conta da sua empresa.', 'benefits' => ['Cobrança Pix com QR Code e link', 'Confirmação automática pelo provedor', 'Conciliação e devoluções'], 'icon' => 'qr-code', 'category' => 'Financeiro'],
        'fiscal' => ['tagline' => 'NFS-e, NF-e e NFC-e num só lugar.', 'benefits' => ['Emissão pelo provedor fiscal homologado', 'Rascunho, validação e cancelamento', 'XML e PDF guardados com segurança'], 'icon' => 'file-text', 'category' => 'Fiscal'],
    ];

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
            ['identifier' => 'payments', 'name' => 'Pagamentos', 'description' => 'Cobranças Pix pela conta da própria empresa no provedor, link e QR Code, confirmação por webhook, conciliação, devoluções e relatórios.', 'dependencies' => [], 'optional_integrations' => ['customers', 'sales', 'finance', 'pos'], 'permissions' => PaymentPermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
            ['identifier' => 'fiscal', 'name' => 'Documentos Fiscais', 'description' => 'Central de NFS-e, NF-e e NFC-e pelo provedor liberado na plataforma: rascunho, validação, emissão, consulta, cancelamento, XML e PDF protegidos e conciliação.', 'dependencies' => [], 'optional_integrations' => ['customers', 'products', 'sales', 'pos', 'finance'], 'permissions' => FiscalPermission::catalog(), 'development_status' => PlatformModule::IMPLEMENTED, 'version' => '1.0.0'],
        ] as $sortOrder => $module) {
            // Dados técnicos (versão, dependências, permissões) seguem o código. Disponibilidade comercial, valores e
            // textos de venda são decididos pelo RootAdmin e não são desfeitos por um novo seed.
            $record = PlatformModule::query()->firstOrNew(['identifier' => $module['identifier']]);
            $commercial = self::COMMERCIAL[$module['identifier']];
            $record->fill(['development_status' => PlatformModule::DEVELOPMENT, ...collect($module)->except(['name', 'description'])->all()]);

            if (! $record->exists) {
                $record->fill(['name' => $module['name'], 'description' => $module['description'], 'sort_order' => $sortOrder + 1]);
            }

            foreach ($commercial as $field => $value) {
                // `business` é o valor padrão antigo da coluna de categoria, nunca escolhido pelo RootAdmin.
                if (blank($record->getAttribute($field)) || ($field === 'category' && $record->getAttribute($field) === 'business')) {
                    $record->setAttribute($field, $value);
                }
            }

            $record->save();
        }

        // Provedores de pagamento implementados: indisponíveis até decisão do RootAdmin, sem desfazer decisão anterior.
        app(ProviderRegistry::class)->ensureRegistered();
        app(FiscalProviderRegistry::class)->ensureRegistered();
    }
}
