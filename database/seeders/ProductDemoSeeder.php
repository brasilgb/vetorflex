<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Services\UnitOfMeasureProvisioner;
use App\Modules\Products\Services\VariantGenerator;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

class ProductDemoSeeder extends Seeder
{
    /**
     * Complementa a empresa de demonstração; por isso só existe em ambientes local e de teste.
     */
    public function run(): void
    {
        $company = Company::query()->where('slug', 'empresa-demonstracao')->first();
        $user = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $company === null || $user === null) {
            $this->command?->warn('ProductDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após CustomerDemoSeeder.');

            return;
        }

        $module = PlatformModule::query()->where('identifier', 'products')->firstOrFail();
        CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);

        $this->seedCatalog($company, $user);
        $this->seedMovements($company, $user);
    }

    private function seedCatalog(Company $company, User $user): void
    {
        app(UnitOfMeasureProvisioner::class)->ensureDefaults($company);
        $unit = fn (string $code): int => UnitOfMeasure::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail()->id;
        $category = fn (string $name, ?int $parentId = null): int => ProductCategory::query()->firstOrCreate(['company_id' => $company->id, 'parent_id' => $parentId, 'name' => $name])->id;
        $brand = fn (string $name): int => ProductBrand::query()->firstOrCreate(['company_id' => $company->id, 'name' => $name])->id;

        UnitOfMeasure::query()->firstOrCreate(['company_id' => $company->id, 'code' => 'RL'], ['name' => 'Rolo']);
        $stationery = $category('Papelaria');
        $writing = $category('Escrita', $stationery);
        $clothing = $category('Vestuário');
        $demoBrand = $brand('Marca Demonstração');
        $exampleBrand = $brand('Marca Exemplo');

        foreach ([
            ['type' => 'simple', 'name' => 'Caderno universitário', 'product_category_id' => $stationery, 'product_brand_id' => $demoBrand, 'cost_price' => '12.50', 'sale_price' => '24.90', 'minimum_stock' => '10', 'initial_stock' => '50'],
            ['type' => 'simple', 'name' => 'Caneta esferográfica', 'product_category_id' => $writing, 'product_brand_id' => $exampleBrand, 'barcode' => 'DEMO-0001', 'barcode_type' => 'internal', 'cost_price' => '0.8550', 'sale_price' => '2.50', 'promotional_price' => '1.99', 'minimum_stock' => '20', 'initial_stock' => '5'],
            ['type' => 'simple', 'name' => 'Papel para presente', 'product_category_id' => $stationery, 'unit_of_measure_id' => $unit('RL'), 'cost_price' => '6.00', 'sale_price' => '14.00', 'minimum_stock' => '2'],
            ['type' => 'simple', 'name' => 'Tecido de algodão', 'product_category_id' => $clothing, 'unit_of_measure_id' => $unit('M'), 'cost_price' => '18.00', 'sale_price' => '32.00', 'minimum_stock' => '5', 'initial_stock' => '12.5'],
            ['type' => 'simple', 'name' => 'Sacola personalizada', 'tracks_inventory' => false, 'sale_price' => '1.00'],
            ['type' => 'variable', 'name' => 'Camiseta básica', 'product_category_id' => $clothing, 'product_brand_id' => $demoBrand],
            ['type' => 'service', 'name' => 'Encadernação', 'sale_price' => '8.00'],
            ['type' => 'service', 'name' => 'Consultoria de estampas', 'unit_of_measure_id' => $unit('H'), 'sale_price' => '90.00'],
        ] as $data) {
            if (Product::query()->where('company_id', $company->id)->where('name', $data['name'])->exists()) {
                continue;
            }

            $product = app(ProductService::class)->create($company, $user, [...$data, 'generate_sku' => true]);

            if ($product->type === ProductType::Variable) {
                $attributes = [['name' => 'Cor', 'values' => ['Branca', 'Preta']], ['name' => 'Tamanho', 'values' => ['M', 'G']]];
                app(VariantGenerator::class)->generate($product, $user, $attributes, ['Branca / M', 'Branca / G', 'Preta / M', 'Preta / G'], ['generate_sku' => true, 'cost_price' => '19.90', 'sale_price' => '49.90', 'minimum_stock' => '3']);
            }
        }
    }

    /**
     * As chaves de idempotência impedem que um novo seed repita as movimentações.
     */
    private function seedMovements(Company $company, User $user): void
    {
        $inventory = app(InventoryService::class);

        foreach ([
            ['Caderno universitário', null, 'recordEntry', '20', 'Reposição de demonstração.'],
            ['Caderno universitário', null, 'recordExit', '8', 'Saída de demonstração.'],
            ['Tecido de algodão', null, 'adjustTo', '11.75', 'Contagem de demonstração.'],
            ['Camiseta básica', 'Branca / M', 'recordEntry', '15', 'Entrada de demonstração.'],
            ['Camiseta básica', 'Preta / G', 'recordEntry', '1', 'Entrada de demonstração.'],
        ] as $position => [$name, $label, $method, $quantity, $reason]) {
            $key = "demo-products-{$position}";

            if (InventoryMovement::query()->where('company_id', $company->id)->where('idempotency_key', $key)->exists()) {
                continue;
            }

            $variant = ProductVariant::query()->where('company_id', $company->id)->where('label', $label)
                ->whereHas('product', fn (Builder $product) => $product->where('name', $name))->first();

            if ($variant !== null) {
                $inventory->{$method}(new StockOperation($company->id, $variant->id, $quantity, user: $user, reason: $reason, idempotencyKey: $key));
            }
        }
    }
}
