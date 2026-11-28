<?php

use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Inventory\Models\InventoryWarehouseBalance;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockModule;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

function stockWarehouse(Company $company, string $code = 'LOJA'): InventoryWarehouse
{
    test()->post('/inventory/warehouses', ['name' => "Depósito {$code}", 'code' => $code])->assertSessionHasNoErrors();

    return InventoryWarehouse::query()->where('company_id', $company->id)->where('code', $code)->sole();
}

function stockMove(ProductVariant $variant, string $type, string $quantity, ?int $warehouseId = null, array $overrides = [])
{
    return test()->post('/inventory/movements', ['type' => $type, 'product_variant_id' => $variant->id, 'quantity' => $quantity, 'inventory_warehouse_id' => $warehouseId, 'reason' => 'Teste', 'idempotency_key' => (string) Str::uuid(), ...$overrides]);
}

test('a company with only the stock module registers goods with initial balance in a warehouse', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $store = stockWarehouse($company);

    $this->post('/inventory/items', [
        'name' => 'Arroz 5 kg', 'sku' => 'arr-5', 'barcode' => '7890000000017', 'category_name' => 'Mercearia', 'unit_code' => 'UN',
        'minimum_stock' => '10', 'maximum_stock' => '100', 'cost_price' => '18.50', 'initial_stock' => '24', 'inventory_warehouse_id' => $store->id,
    ])->assertSessionHasNoErrors();

    $variant = ProductVariant::query()->where('company_id', $company->id)->sole();
    expect($variant)->sku->toBe('ARR-5')->minimum_stock->toBe('10.0000')->maximum_stock->toBe('100.0000')
        ->and($variant->product->tracks_inventory)->toBeTrue()
        ->and($variant->product->category->name)->toBe('Mercearia');
    expect(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('24.0000');
    expect(InventoryWarehouseBalance::query()->where('inventory_warehouse_id', $store->id)->sole()->quantity)->toBe('24.0000');
    expect(app(StockModule::class)->settings($company->refresh())['started_at'])->toBe(now($company->timezone)->format('Y-m-d'));

    $this->get('/inventory')->assertOk()->assertInertia(fn ($page) => $page->component('inventory/dashboard')->where('indicators.items', 1)->where('indicators.with_balance', 1));
    $this->get('/products')->assertForbidden();
    $this->get('/purchases')->assertForbidden();
    $this->get('/sales')->assertForbidden();
});

test('entries, exits and adjustments are per warehouse and keep the company total equal to the warehouses', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $variant = stockItem($company, '10');
    $store = stockWarehouse($company);

    stockMove($variant, 'entry', '5', $store->id)->assertSessionHasNoErrors();
    stockMove($variant, 'exit', '3', $store->id)->assertSessionHasNoErrors();
    stockMove($variant, 'exit', '3', $store->id)->assertSessionHasErrors('quantity');
    stockMove($variant, 'adjustment', '7')->assertSessionHasNoErrors();

    $balances = InventoryWarehouseBalance::query()->where('product_variant_id', $variant->id)->pluck('quantity', 'inventory_warehouse_id');
    expect($balances[$store->id])->toBe('2.0000')
        ->and($balances[app(WarehouseService::class)->defaultFor($company->id)->id])->toBe('7.0000')
        ->and(InventoryBalance::query()->where('product_variant_id', $variant->id)->sole()->quantity)->toBe('9.0000')
        ->and((string) BigDecimal::of((string) InventoryMovement::query()->where('product_variant_id', $variant->id)->sum('quantity'))->toScale(4))->toBe('9.0000');
    stockMove($variant, 'exit', '1', null, ['reason' => ''])->assertSessionHasErrors('reason');
});

test('negative stock is refused by default and allowed only when the company enables it', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $variant = stockItem($company, '1');

    stockMove($variant, 'exit', '2')->assertSessionHasErrors('quantity');
    $this->put('/inventory/settings', ['allow_negative' => true, 'started_at' => null])->assertSessionHasNoErrors();
    stockMove($variant, 'exit', '2')->assertSessionHasNoErrors();

    expect(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('-1.0000');
});

test('transfers move between warehouses without changing the total, are idempotent and never leave the origin negative', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $variant = stockItem($company, '10');
    $main = app(WarehouseService::class)->defaultFor($company->id);
    $store = stockWarehouse($company);
    $key = (string) Str::uuid();
    $data = ['from_warehouse_id' => $main->id, 'to_warehouse_id' => $store->id, 'items' => [['product_variant_id' => $variant->id, 'quantity' => '4']], 'idempotency_key' => $key];

    $this->post('/inventory/transfers', $data)->assertSessionHasNoErrors();
    $this->post('/inventory/transfers', $data)->assertSessionHasNoErrors();

    expect(InventoryTransfer::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->where('reference_type', 'inventory_transfer')->count())->toBe(2)
        ->and(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('10.0000')
        ->and(app(InventoryService::class)->warehouseBalancesOf($company->id, [$variant->id])[$variant->id])->toBe([$main->id => '6.0000', $store->id => '4.0000']);

    $this->put('/inventory/settings', ['allow_negative' => true])->assertSessionHasNoErrors();
    $this->post('/inventory/transfers', [...$data, 'items' => [['product_variant_id' => $variant->id, 'quantity' => '5']], 'from_warehouse_id' => $store->id, 'to_warehouse_id' => $main->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('quantity');
    $this->post('/inventory/transfers', [...$data, 'to_warehouse_id' => $main->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('to_warehouse_id');

    $transferMovement = InventoryMovement::query()->where('reference_type', 'inventory_transfer')->first();
    $this->post("/inventory/movements/{$transferMovement->id}/reverse", ['reason' => 'Erro'])->assertSessionHasErrors('movement');
});

test('manual movements can be reversed once, and integrated movements are corrected only at their origin', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $variant = stockItem($company, '10');
    stockMove($variant, 'entry', '5')->assertSessionHasNoErrors();
    $entry = InventoryMovement::query()->where('type', 'entry')->sole();

    $this->post("/inventory/movements/{$entry->id}/reverse", ['reason' => 'Lançada em duplicidade'])->assertSessionHasNoErrors();
    $this->post("/inventory/movements/{$entry->id}/reverse", ['reason' => 'De novo'])->assertSessionHasNoErrors();

    expect(InventoryMovement::query()->where('reversal_of_id', $entry->id)->count())->toBe(1)
        ->and(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('10.0000');
});

test('the default warehouse cannot be deactivated and inactive warehouses refuse movements', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $variant = stockItem($company, '1');
    $main = app(WarehouseService::class)->defaultFor($company->id);
    $store = stockWarehouse($company);

    $this->put("/inventory/warehouses/{$main->id}", ['name' => 'Principal', 'code' => 'PRINCIPAL', 'is_active' => false])->assertSessionHasErrors('is_active');
    $this->put("/inventory/warehouses/{$store->id}", ['name' => 'Loja', 'code' => 'LOJA', 'is_active' => false])->assertSessionHasNoErrors();
    stockMove($variant, 'entry', '1', $store->id)->assertSessionHasErrors('inventory_warehouse_id');
});

test('permissions are individual and records of another company are not reachable', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    actingAsInventoryMember($other);
    $foreign = stockItem($other, '3');
    $foreignWarehouse = stockWarehouse($other, 'OUTRA');

    actingAsInventoryMember($company, [InventoryPermission::View]);
    $variant = stockItem($company, '3');

    $this->get('/inventory/items')->assertOk();
    $this->get('/inventory/reports')->assertForbidden();
    $this->get('/inventory/settings')->assertForbidden();
    $this->post('/inventory/items', ['name' => 'X'])->assertForbidden();
    $this->post('/inventory/transfers', [])->assertForbidden();
    stockMove($variant, 'entry', '1')->assertForbidden();
    $this->get("/inventory/items/{$foreign->id}")->assertNotFound();
    $this->put("/inventory/warehouses/{$foreignWarehouse->id}", ['name' => 'X', 'code' => 'X'])->assertNotFound();

    actingAsInventoryMember($company, [InventoryPermission::View, InventoryPermission::Move]);
    stockMove($foreign, 'entry', '1')->assertNotFound();
    stockMove($variant, 'entry', '1', $foreignWarehouse->id)->assertSessionHasErrors('inventory_warehouse_id');
    stockMove($variant, 'adjustment', '1')->assertForbidden();
});

test('deactivating the module keeps the data and reactivating shows the same balances', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $variant = stockItem($company, '8');

    CompanyModule::query()->where('company_id', $company->id)->update(['deactivated_at' => now()]);
    $this->get('/inventory')->assertForbidden();
    expect(InventoryMovement::query()->where('product_variant_id', $variant->id)->count())->toBe(1);

    CompanyModule::query()->where('company_id', $company->id)->update(['deactivated_at' => null]);
    $this->get("/inventory/items/{$variant->id}")->assertOk()->assertInertia(fn ($page) => $page->component('inventory/items/show')->where('item.balance', '8.0000'));
});

test('goods are imported and exported by CSV without duplicating existing codes', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    stockItem($company)->update(['sku' => 'JA-EXISTE']);
    $csv = "\u{FEFF}nome;sku;unidade;estoque_minimo;saldo_inicial;deposito\nFeijão 1 kg;FEI-1;UN;5;12;PRINCIPAL\nRepetido;JA-EXISTE;UN;;3;\n;SEM-NOME;UN;;;\n";

    $this->post('/inventory/items/import', ['file' => UploadedFile::fake()->createWithContent('estoque.csv', $csv)])->assertSessionHasNoErrors();

    $imported = ProductVariant::query()->where('company_id', $company->id)->where('sku', 'FEI-1')->sole();
    expect(app(InventoryService::class)->balanceOf($company->id, $imported->id))->toBe('12.0000')
        ->and(ProductVariant::query()->where('company_id', $company->id)->count())->toBe(2);
    expect($this->get('/inventory/items/export')->assertOk()->streamedContent())->toStartWith("\u{FEFF}")->toContain('FEI-1');
});

test('every stock report builds and exports to CSV, and the value needs the cost permission', function (string $type) {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    stockItem($company, '5', minimum: '10');

    $this->get("/inventory/reports?type={$type}")->assertOk()->assertInertia(fn ($page) => $page->component('inventory/reports')->where('report.type', $type));
    expect($this->get("/inventory/reports/csv?type={$type}")->assertOk()->streamedContent())->toStartWith("\u{FEFF}");
})->with(['balances', 'low_stock', 'above_max', 'no_movement', 'movements', 'by_warehouse', 'valuation']);

test('without the cost permission the dashboard and reports hide the stock value', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company, [InventoryPermission::View, InventoryPermission::ViewReports]);
    stockItem($company, '5', minimum: '10');

    $this->get('/inventory')->assertInertia(fn ($page) => $page->where('indicators.value', null)->where('indicators.low_stock', 1));
    $this->get('/inventory/reports?type=valuation')->assertInertia(fn ($page) => $page->where('report.type', 'balances'));
});

test('every stock page renders', function (string $url, string $component) {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    stockItem($company, '5');

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/inventory', 'inventory/dashboard'],
    ['/inventory/items', 'inventory/items/index'],
    ['/inventory/movements', 'inventory/movements'],
    ['/inventory/transfers', 'inventory/transfers'],
    ['/inventory/warehouses', 'inventory/warehouses'],
    ['/inventory/counts', 'inventory/counts/index'],
    ['/inventory/reports', 'inventory/reports'],
    ['/inventory/settings', 'inventory/settings'],
]);
