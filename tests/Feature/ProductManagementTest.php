<?php

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductPriceChange;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\CustomerDemoSeeder;
use Database\Seeders\PlatformModuleSeeder;
use Database\Seeders\ProductDemoSeeder;
use Inertia\Testing\AssertableInertia;

test('creates a simple product from only a name with one default sellable unit', function () {
    $company = Company::factory()->create();
    $user = actingAsProductsMember($company);

    $response = $this->post('/products', ['type' => 'simple', 'name' => 'Caneta']);

    $product = Product::query()->sole();
    $response->assertRedirect("/products/{$product->id}");
    expect($product)->company_id->toBe($company->id)->created_by->toBe($user->id)->unit->code->toBe('UN');
    expect($product->variants()->sole())->is_default->toBeTrue()->sku->toBeNull();
    expect(AuditLog::query()->where('event', 'product.created')->exists())->toBeTrue();
});

test('records the initial stock as a movement and generates the sku', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);

    $this->post('/products', ['type' => 'simple', 'name' => 'Caneta', 'generate_sku' => true, 'initial_stock' => '7'])->assertSessionHasNoErrors();

    $variant = ProductVariant::query()->sole();
    expect($variant->sku)->toBe(sprintf('P%06d-%d', $variant->product_id, $variant->id));
    expect(InventoryMovement::query()->sole())->type->value->toBe('initial')->quantity->toBe('7.0000')->balance_after->toBe('7.0000');
    expect($variant->balance->quantity)->toBe('7.0000');
});

test('rolls back the product when the initial stock is rejected by its unit', function () {
    actingAsProductsMember(Company::factory()->create());

    $this->post('/products', ['type' => 'simple', 'name' => 'Caneta', 'initial_stock' => '1.5'])->assertSessionHasErrors('quantity');

    expect(Product::query()->count())->toBe(0);
});

test('a service never controls stock', function () {
    actingAsProductsMember(Company::factory()->create());

    $this->post('/products', ['type' => 'service', 'name' => 'Instalação', 'tracks_inventory' => true, 'initial_stock' => '5'])->assertSessionHasNoErrors();

    expect(Product::query()->sole())->tracks_inventory->toBeFalse()->unit->code->toBe('SERV');
    expect(InventoryMovement::query()->count())->toBe(0);
});

test('rejects invalid product data', function (array $data, string $field) {
    actingAsProductsMember(Company::factory()->create());

    $this->post('/products', ['type' => 'simple', 'name' => 'Caneta', ...$data])->assertSessionHasErrors($field);

    expect(Product::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'unknown type' => [['type' => 'kit'], 'type'],
    'negative sale price' => [['sale_price' => '-1'], 'sale_price'],
    'negative cost' => [['cost_price' => '-0.01'], 'cost_price'],
    'fractional cents in sale price' => [['sale_price' => '1.999'], 'sale_price'],
    'exponent notation' => [['sale_price' => '1e3'], 'sale_price'],
    'promotional above sale price' => [['sale_price' => '10', 'promotional_price' => '10.01'], 'promotional_price'],
    'promotional without sale price' => [['promotional_price' => '5'], 'sale_price'],
    'invalid gtin' => [['barcode' => '7891000315508', 'barcode_type' => 'gtin'], 'barcode'],
    'barcode without type' => [['barcode' => 'ABC'], 'barcode_type'],
    'short ncm' => [['ncm' => '1234'], 'ncm'],
]);

test('normalizes the sku and accepts valid GTINs and internal codes', function (array $data, string $barcode) {
    actingAsProductsMember(Company::factory()->create());

    $this->post('/products', ['type' => 'simple', 'name' => 'Caneta', 'sku' => ' can-01 ', ...$data])->assertSessionHasNoErrors();

    expect(ProductVariant::query()->sole())->sku->toBe('CAN-01')->barcode->toBe($barcode);
})->with([
    'ean-13 with spaces' => [['barcode' => '4006381 333931', 'barcode_type' => 'gtin'], '4006381333931'],
    'ean-8' => [['barcode' => '73513537', 'barcode_type' => 'gtin'], '73513537'],
    'upc-a' => [['barcode' => '036000291452', 'barcode_type' => 'gtin'], '036000291452'],
    'internal code' => [['barcode' => 'int-9', 'barcode_type' => 'internal'], 'INT-9'],
]);

test('the sku is unique within the company only', function () {
    $company = Company::factory()->create();
    ProductVariant::factory()->create(['sku' => 'A1']);
    actingAsProductsMember($company);

    $this->post('/products', ['type' => 'simple', 'name' => 'Primeiro', 'sku' => 'A1'])->assertSessionHasNoErrors();
    $this->post('/products', ['type' => 'simple', 'name' => 'Segundo', 'sku' => 'a1'])->assertSessionHasErrors(['sku' => 'Este valor já está em uso nesta empresa.']);

    expect($company->id)->toBe(Product::query()->where('name', 'Primeiro')->sole()->company_id);
});

test('rejects associations with records of another company or inactive ones', function (Closure $association) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    [$field, $id] = $association($company);

    $this->post('/products', ['type' => 'simple', 'name' => 'Caneta', $field => $id])->assertSessionHasErrors($field);
})->with([
    'foreign category' => [fn () => ['product_category_id', ProductCategory::factory()->create()->id]],
    'foreign brand' => [fn () => ['product_brand_id', ProductBrand::factory()->create()->id]],
    'foreign unit' => [fn () => ['unit_of_measure_id', UnitOfMeasure::factory()->create()->id]],
    'inactive category' => [fn (Company $company) => ['product_category_id', ProductCategory::factory()->inactive()->create(['company_id' => $company->id])->id]],
]);

test('updates the product and records only the prices that changed', function () {
    $company = Company::factory()->create();
    $user = actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $variant = ProductVariant::factory()->for($product)->create(['sale_price' => '10.00', 'cost_price' => '4.0000']);

    $this->put("/products/{$product->id}", ['name' => 'Novo nome', 'unit_of_measure_id' => $product->unit_of_measure_id, 'sale_price' => '12.5', 'cost_price' => '4'])
        ->assertRedirect("/products/{$product->id}")->assertSessionHasNoErrors();

    expect($product->refresh()->name)->toBe('Novo nome');
    expect($variant->refresh()->sale_price)->toBe('12.50');
    expect(ProductPriceChange::query()->sole())->field->toBe('sale_price')->old_value->toBe('10.0000')->new_value->toBe('12.5000')->user_name->toBe($user->name);
});

test('the balance, type and company cannot be changed through the product form', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    ProductVariant::factory()->for($product)->create();

    $this->put("/products/{$product->id}", ['name' => 'X', 'unit_of_measure_id' => $product->unit_of_measure_id, 'type' => 'service', 'company_id' => 999, 'balance' => '50', 'quantity' => '50', 'initial_stock' => '50'])->assertSessionHasNoErrors();

    expect($product->refresh())->type->value->toBe('simple')->company_id->toBe($company->id);
    expect(InventoryBalance::query()->count())->toBe(0);
});

test('the unit cannot change after movements and stock control cannot be disabled with a balance', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $this->post('/products', ['type' => 'simple', 'name' => 'Caneta', 'initial_stock' => '3']);
    $product = Product::query()->sole();
    $otherUnit = UnitOfMeasure::factory()->create(['company_id' => $company->id]);

    $this->put("/products/{$product->id}", ['name' => 'Caneta', 'unit_of_measure_id' => $otherUnit->id])->assertSessionHasErrors('unit_of_measure_id');
    $this->put("/products/{$product->id}", ['name' => 'Caneta', 'unit_of_measure_id' => $product->unit_of_measure_id, 'tracks_inventory' => false])->assertSessionHasErrors('tracks_inventory');

    expect($product->refresh())->tracks_inventory->toBeTrue()->unit->code->toBe('UN');
});

test('deactivates and reactivates without deleting', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->patch("/products/{$product->id}/deactivate")->assertRedirect();
    expect($product->refresh()->is_active)->toBeFalse();

    $this->patch("/products/{$product->id}/reactivate")->assertRedirect();
    expect($product->refresh()->is_active)->toBeTrue();
});

test('records of another company respond 404 before validation', function (string $method, string $suffix) {
    actingAsProductsMember(Company::factory()->create());
    $product = Product::factory()->create();

    $this->json($method, "/products/{$product->id}{$suffix}")->assertNotFound();
})->with([
    'show' => ['GET', ''],
    'edit' => ['GET', '/edit'],
    'update' => ['PUT', ''],
    'deactivate' => ['PATCH', '/deactivate'],
    'variants' => ['POST', '/variants'],
    'images' => ['POST', '/images'],
]);

test('each operation requires its own permission', function (string $method, string $path) {
    $company = Company::factory()->create();
    actingAsProductsMember($company, [ProductPermission::View]);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->json($method, str_replace('{id}', (string) $product->id, $path))->assertForbidden();
})->with([
    ['POST', '/products'], ['PUT', '/products/{id}'], ['PATCH', '/products/{id}/deactivate'], ['PATCH', '/products/{id}/reactivate'],
    ['POST', '/products/{id}/variants'], ['POST', '/products/{id}/images'], ['POST', '/products/categories'], ['POST', '/products/brands'],
    ['POST', '/products/units'], ['GET', '/products/movements'], ['POST', '/products/movements'], ['GET', '/products/export'], ['GET', '/products/imports/create'], ['POST', '/products/imports'],
]);

test('the module is unavailable when disabled for the company and to guests', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    CompanyModule::query()->update(['deactivated_at' => now()]);

    $this->get('/products')->assertForbidden();

    auth()->logout();
    $this->get('/products')->assertRedirect('/login');
});

test('hides costs and balances from members without permission and ignores submitted costs', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company, [ProductPermission::View, ProductPermission::Update]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $variant = ProductVariant::factory()->for($product)->create(['cost_price' => '4']);
    $variant->priceChanges()->create(['company_id' => $company->id, 'field' => 'cost_price', 'new_value' => '4', 'changed_at' => now()]);

    $this->get("/products/{$product->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('products/show')
        ->missing('variants.0.cost_price')->missing('variants.0.margin')->missing('variants.0.balance')
        ->has('priceChanges', 0)->has('movements', 0));
    $this->put("/products/{$product->id}", ['name' => 'X', 'unit_of_measure_id' => $product->unit_of_measure_id, 'cost_price' => '1'])->assertSessionHasNoErrors();

    expect($variant->refresh()->cost_price)->toBe('4.0000');
});

test('prices and costs can only be changed with their own permissions', function (array $permissions, string $cost, string $sale) {
    $company = Company::factory()->create();
    actingAsProductsMember($company, [ProductPermission::View, ProductPermission::Update, ...$permissions]);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $variant = ProductVariant::factory()->for($product)->create(['cost_price' => '4', 'sale_price' => '10']);

    $this->put("/products/{$product->id}", ['name' => 'X', 'unit_of_measure_id' => $product->unit_of_measure_id, 'cost_price' => '1', 'sale_price' => '9'])->assertSessionHasNoErrors();

    expect($variant->refresh())->cost_price->toBe($cost)->sale_price->toBe($sale);
})->with([
    'viewing costs' => [[ProductPermission::ViewCost], '4.0000', '10.00'],
    'managing costs without viewing them' => [[ProductPermission::ManageCost], '4.0000', '10.00'],
    'managing costs' => [[ProductPermission::ViewCost, ProductPermission::ManageCost], '1.0000', '10.00'],
    'managing prices' => [[ProductPermission::ManagePrices], '4.0000', '9.00'],
]);

test('the indicators use real balances and respect permissions', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    Product::factory()->inactive()->create(['company_id' => $company->id]);
    Product::factory()->service()->create(['company_id' => $company->id]);
    $this->post('/products', ['type' => 'simple', 'name' => 'Baixo', 'cost_price' => '1.1111', 'minimum_stock' => '5', 'initial_stock' => '3']);
    $this->post('/products', ['type' => 'simple', 'name' => 'Sem custo', 'initial_stock' => '2']);
    $this->post('/products', ['type' => 'simple', 'name' => 'Zerado', 'cost_price' => '9']);

    $this->get('/products')->assertInertia(fn (AssertableInertia $page) => $page->where('indicators', [
        'active' => 4, 'inactive' => 1, 'controlled' => 3, 'low' => 1, 'out' => 1, 'stock_value' => '3.33', 'stock_without_cost' => 1,
    ]));

    actingAsProductsMember($company, [ProductPermission::View, ProductPermission::ViewInventory]);
    $this->get('/products')->assertInertia(fn (AssertableInertia $page) => $page->has('indicators.low')->missing('indicators.stock_value'));

    actingAsProductsMember($company, [ProductPermission::View, ProductPermission::ViewCost]);
    $this->get('/products')->assertInertia(fn (AssertableInertia $page) => $page->missing('indicators.low')->missing('indicators.stock_value'));
});

test('shows margin over the sale price and markup over the cost', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    ProductVariant::factory()->for($product)->create(['cost_price' => '80', 'sale_price' => '100']);

    $this->get("/products/{$product->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('variants.0.margin', '20.00')->where('variants.0.markup', '25.00'));
});

test('lists only company products and filters them', function (string $query, array $names) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $parent = ProductCategory::factory()->create(['id' => 901, 'company_id' => $company->id]);
    $child = ProductCategory::factory()->create(['company_id' => $company->id, 'parent_id' => $parent->id]);
    $pen = Product::factory()->create(['company_id' => $company->id, 'name' => 'Caneta', 'product_category_id' => $child->id]);
    ProductVariant::factory()->for($pen)->create(['sku' => 'CAN-1', 'barcode' => '4006381333931', 'minimum_stock' => '5']);
    ProductVariant::factory()->for(Product::factory()->inactive()->create(['company_id' => $company->id, 'name' => 'Papel']))->create();
    Product::factory()->service()->create(['company_id' => $company->id, 'name' => 'Entrega']);
    Product::factory()->create(['name' => 'De outra empresa']);

    $this->get("/products?{$query}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('products/index')
        ->where('products.data', fn ($products) => collect($products)->pluck('name')->all() === $names));
})->with([
    'all' => ['', ['Caneta', 'Entrega', 'Papel']],
    'barcode' => ['search=4006381333931', ['Caneta']],
    'sku' => ['search=can-1', ['Caneta']],
    'type' => ['type=service', ['Entrega']],
    'inactive' => ['active=0', ['Papel']],
    'parent category' => ['category=901', ['Caneta']],
    'low stock' => ['stock=low', ['Caneta']],
    'out of stock' => ['stock=out', ['Caneta', 'Papel']],
    'active out of stock' => ['stock=out&active=1', ['Caneta']],
    'controlled stock' => ['stock=controlled', ['Caneta', 'Papel']],
]);

test('the form pages render', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);

    $this->get('/products/create')->assertInertia(fn (AssertableInertia $page) => $page->component('products/create')->has('units', 11)->where('tenant.activeCompany.timezone', $company->timezone));

    $product = Product::factory()->create(['company_id' => $company->id]);
    $this->get("/products/{$product->id}/edit")->assertInertia(fn (AssertableInertia $page) => $page->component('products/edit')->where('product.id', $product->id));
});

test('the demo catalog is idempotent and restricted to local and testing', function (string $environment, int $products) {
    $this->seed([PlatformModuleSeeder::class, CustomerDemoSeeder::class]);
    $this->app->detectEnvironment(fn (): string => $environment);

    (new ProductDemoSeeder)->run();
    (new ProductDemoSeeder)->run();

    expect(Product::query()->count())->toBe($products);
    expect(InventoryMovement::query()->count())->toBe($products === 0 ? 0 : 8);
    expect(User::query()->where('email', 'demo@vetornew.test')->count())->toBe(1);
})->with(['production' => ['production', 0], 'local' => ['local', 8]]);
