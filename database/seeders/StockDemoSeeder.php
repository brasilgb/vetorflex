<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Inventory\Services\InventoryCountService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockItems;
use App\Modules\Inventory\Services\StockModule;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosCheckout;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Pos\Services\PosTerminalService;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchases\Services\QuickPurchaseService;
use App\Modules\Purchases\Services\SupplierService;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Services\QuickSaleService;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Database\Seeder;

/**
 * Cenários do módulo Estoque gerados pelos serviços reais, em cinco combinações:
 * - "Depósito Somente Estoque": dois depósitos, mercadorias, entradas, saídas, transferência, inventário aprovado,
 *   item abaixo do mínimo e item parado;
 * - "Distribuidora Estoque + Compras": recebimento de compra dá entrada no estoque;
 * - "Loja Estoque + Vendas": venda rápida dá saída;
 * - "Mercearia Estoque + PDV": venda no caixa dá saída;
 * - "Empresa Demonstração" (todos os módulos): Estoque ativado depois, com depósito da loja, transferência e
 *   inventário sobre o saldo que já existia.
 *
 * Restrito a local e testing. Repetir o seed não duplica: cada cenário só roda uma vez por empresa.
 */
class StockDemoSeeder extends Seeder
{
    private Company $company;

    private User $user;

    public function run(): void
    {
        $user = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $user === null) {
            $this->command?->warn('StockDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após CustomerDemoSeeder.');

            return;
        }

        $this->user = $user;
        $this->stockOnly();
        $this->withPurchases();
        $this->withSales();
        $this->withPos();
        $this->allModules();
    }

    private function stockOnly(): void
    {
        if (! $this->begin('deposito-somente-estoque', 'Depósito Somente Estoque', [])) {
            return;
        }

        $store = $this->warehouse('LOJA', 'Loja', 'Balcão de atendimento');
        $rice = $this->item('Arroz tipo 1 — 5 kg', 'ARR-5', '7890000000101', 'Mercearia', '22.50', '60', '20', '120');
        $beans = $this->item('Feijão carioca — 1 kg', 'FEI-1', '7890000000102', 'Mercearia', '7.80', '8', '15', '80');
        $flour = $this->item('Farinha de trigo — 1 kg', 'FAR-1', null, 'Mercearia', '4.90', '30', '10', null, 'KG');
        $this->item('Vela decorativa', 'VELA-01', null, 'Utilidades', '9.00', '12', '0', null);

        $inventory = app(InventoryService::class);
        $inventory->recordEntry($this->operation($rice, '40', 'Reposição do fornecedor.', 'demo-stock-entry-1', unitCost: '22.00'));
        $inventory->recordExit($this->operation($beans, '3', 'Avaria no transporte.', 'demo-stock-exit-1'));
        $inventory->transfer($this->company, app(WarehouseService::class)->defaultFor($this->company->id)->id, $store->id, [$rice->id => '25', $flour->id => '12.5'], $this->user, 'Abastecimento da loja.', 'demo-stock-transfer-1');
        $this->approvedCount($store, [$rice->id => '24', $flour->id => '12.5'], 'Conferência da loja');
    }

    private function withPurchases(): void
    {
        if (! $this->begin('distribuidora-estoque-compras', 'Distribuidora Estoque + Compras', ['purchases'])) {
            return;
        }

        $item = $this->item('Detergente neutro 500 ml', 'DET-500', null, 'Limpeza', '2.10', '0', '24', '200');
        $supplier = app(SupplierService::class)->create($this->company, $this->user, ['type' => 'company', 'name' => 'Atacado Limpeza Exemplo', 'internal_code' => 'DEMO-FORN-1']);
        app(QuickPurchaseService::class)->register($this->company, $this->user, [
            'supplier_profile_id' => $supplier->id,
            'order_date' => now($this->company->timezone)->subDays(2)->format('Y-m-d'),
            'payment_condition' => 'cash',
            'payment_method' => 'pix',
            'document_reference' => 'NF 1001',
            'items' => [['product_variant_id' => $item->id, 'quantity' => '96', 'unit_cost' => '2.05']],
            'idempotency_key' => 'demo-stock-purchase-1',
        ]);
    }

    private function withSales(): void
    {
        if (! $this->begin('loja-estoque-vendas', 'Loja Estoque + Vendas', ['sales'])) {
            return;
        }

        $item = $this->item('Caneca de cerâmica', 'CAN-01', null, 'Presentes', '11.00', '30', '5', '50', price: '29.90');
        app(QuickSaleService::class)->register($this->company, $this->user, [
            'order_date' => now($this->company->timezone)->format('Y-m-d'),
            'delivery_method' => 'pickup',
            'payment_condition' => 'cash',
            'payment_method' => 'cash',
            'items' => [['product_variant_id' => $item->id, 'quantity' => '4']],
            'idempotency_key' => 'demo-stock-sale-1',
        ], SaleAbilities::full());
    }

    private function withPos(): void
    {
        if (! $this->begin('mercearia-estoque-pdv', 'Mercearia Estoque + PDV', ['pos'])) {
            return;
        }

        $item = $this->item('Refrigerante lata 350 ml', 'REF-350', '7890000000201', 'Bebidas', '2.40', '48', '24', '240', price: '5.00');
        $terminal = app(PosTerminalService::class)->save($this->company, $this->user, new PosTerminal, ['name' => 'Caixa 01', 'code' => 'CX01', 'is_active' => true, 'operators' => []]);
        $session = app(PosSessionService::class)->open($this->company, $this->user, $terminal, '50.00', null, 'demo-stock-pos-open');
        app(PosCheckout::class)->finalize($session, $this->user, [
            'items' => [['product_variant_id' => $item->id, 'quantity' => '6']],
            'payments' => [['method' => 'cash', 'amount' => '30.00']],
            'idempotency_key' => 'demo-stock-pos-sale-1',
        ]);
    }

    /** Estoque ativado depois na empresa com todos os módulos: o saldo existente fica no depósito principal. */
    private function allModules(): void
    {
        $company = Company::query()->where('slug', 'empresa-demonstracao')->first();

        if ($company === null) {
            return;
        }

        $this->company = $company;
        $this->activate('inventory');

        if (InventoryWarehouse::query()->where('company_id', $company->id)->where('code', 'LOJA')->exists()) {
            return;
        }

        app(StockModule::class)->ensureStarted($company, $this->user);
        $store = $this->warehouse('LOJA', 'Loja', 'Vitrine e balcão');
        $notebook = ProductVariant::query()->where('company_id', $company->id)->whereHas('product', fn ($query) => $query->where('name', 'Caderno universitário'))->first();

        if ($notebook !== null && BigDecimal::of(app(InventoryService::class)->balanceOf($company->id, $notebook->id))->isGreaterThanOrEqualTo('5')) {
            app(InventoryService::class)->transfer($company, app(WarehouseService::class)->defaultFor($company->id)->id, $store->id, [$notebook->id => '5'], $this->user, 'Exposição na loja.', 'demo-stock-all-transfer-1');
            $this->approvedCount($store, [$notebook->id => '4'], 'Conferência da vitrine');
        }
    }

    /**
     * Cria a empresa (ou a encontra) com Estoque e os módulos pedidos; devolve falso se o cenário já foi gerado.
     *
     * @param  list<string>  $modules
     */
    private function begin(string $slug, string $name, array $modules): bool
    {
        $this->company = Company::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => 'BRL']);

        if (! $this->company->users()->whereKey($this->user->id)->exists()) {
            $this->company->users()->attach($this->user, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
        }

        foreach (['inventory', ...$modules] as $identifier) {
            $this->activate($identifier);
        }

        return ! ProductVariant::query()->where('company_id', $this->company->id)->exists();
    }

    private function activate(string $identifier): void
    {
        $module = PlatformModule::query()->where('identifier', $identifier)->firstOrFail();
        CompanyModule::query()->firstOrCreate(['company_id' => $this->company->id, 'platform_module_id' => $module->id], ['activated_by' => $this->user->id, 'activated_at' => now()]);
    }

    private function warehouse(string $code, string $name, ?string $location): InventoryWarehouse
    {
        return InventoryWarehouse::query()->where('company_id', $this->company->id)->where('code', $code)->first()
            ?? app(WarehouseService::class)->save($this->company, $this->user, new InventoryWarehouse, ['name' => $name, 'code' => $code, 'location' => $location]);
    }

    private function item(string $name, string $sku, ?string $barcode, string $category, string $cost, string $initial, string $minimum, ?string $maximum, string $unit = 'UN', ?string $price = null): ProductVariant
    {
        return app(StockItems::class)->create($this->company, $this->user, [
            'name' => $name, 'sku' => $sku, 'barcode' => $barcode, 'category_name' => $category, 'unit_code' => $unit,
            'cost_price' => $cost, 'sale_price' => $price, 'minimum_stock' => $minimum, 'maximum_stock' => $maximum,
            'initial_stock' => $initial === '0' ? null : $initial,
        ]);
    }

    private function operation(ProductVariant $variant, string $quantity, string $reason, string $key, ?string $unitCost = null): StockOperation
    {
        return new StockOperation($this->company->id, $variant->id, $quantity, user: $this->user, reason: $reason, idempotencyKey: $key, unitCost: $unitCost);
    }

    /** @param array<int, string> $counted */
    private function approvedCount(InventoryWarehouse $warehouse, array $counted, string $description): void
    {
        $counts = app(InventoryCountService::class);
        $count = $counts->create($this->company, $this->user, $warehouse->id, array_keys($counted), $description);
        $items = $count->items()->pluck('id', 'product_variant_id');
        $counts->record($count, $this->user, array_combine(array_map(fn (int $variantId): int => $items[$variantId], array_keys($counted)), array_values($counted)));
        $counts->approve(InventoryCount::query()->findOrFail($count->id), $this->user);
    }
}
