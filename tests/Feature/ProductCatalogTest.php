<?php

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia;

test('creates, renames and deactivates a category', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);

    $this->post('/products/categories', ['name' => 'Roupas'])->assertSessionHasNoErrors();
    $category = ProductCategory::query()->sole();
    $this->put("/products/categories/{$category->id}", ['name' => 'Vestuário', 'is_active' => false])->assertSessionHasNoErrors();

    expect($category->refresh())->company_id->toBe($company->id)->name->toBe('Vestuário')->is_active->toBeFalse();
});

test('rejects circular category hierarchies', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $root = ProductCategory::factory()->create(['company_id' => $company->id]);
    $child = ProductCategory::factory()->create(['company_id' => $company->id, 'parent_id' => $root->id]);
    $grandchild = ProductCategory::factory()->create(['company_id' => $company->id, 'parent_id' => $child->id]);

    $this->put("/products/categories/{$root->id}", ['name' => $root->name, 'parent_id' => $root->id])->assertSessionHasErrors('parent_id');
    $this->put("/products/categories/{$root->id}", ['name' => $root->name, 'parent_id' => $grandchild->id])->assertSessionHasErrors('parent_id');

    expect($root->refresh()->parent_id)->toBeNull();
});

test('category names are unique per level and parents belong to the company', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $root = ProductCategory::factory()->create(['company_id' => $company->id, 'name' => 'Roupas']);

    $this->post('/products/categories', ['name' => 'Roupas'])->assertSessionHasErrors('name');
    $this->post('/products/categories', ['name' => 'Roupas', 'parent_id' => $root->id])->assertSessionHasNoErrors();
    $this->post('/products/categories', ['name' => 'Outra', 'parent_id' => ProductCategory::factory()->create()->id])->assertSessionHasErrors('parent_id');
});

test('manages brands and prevents duplicates in the company', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);

    $this->post('/products/brands', ['name' => 'Acme'])->assertSessionHasNoErrors();
    $this->post('/products/brands', ['name' => 'Acme'])->assertSessionHasErrors('name');
    $brand = ProductBrand::query()->sole();
    $this->put("/products/brands/{$brand->id}", ['name' => 'Acme', 'is_active' => false])->assertSessionHasNoErrors();

    expect($brand->refresh()->is_active)->toBeFalse();
});

test('provisions the common units once and keeps them fixed', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);

    $this->get('/products/units')->assertInertia(fn (AssertableInertia $page) => $page->component('products/units')->where('records.total', 11));
    $this->get('/products/units');
    $unit = UnitOfMeasure::query()->where('code', 'KG')->sole();
    $this->put("/products/units/{$unit->id}", ['code' => 'KG', 'name' => 'Quilo'])->assertSessionHasErrors('code');
    $this->put("/products/units/{$unit->id}", ['code' => 'KG', 'name' => 'Quilograma', 'is_active' => false])->assertSessionHasNoErrors();

    expect($unit->refresh())->name->toBe('Quilograma')->is_active->toBeFalse();
});

test('a custom unit in use keeps accepting fractions', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $this->post('/products/units', ['code' => 'ton', 'name' => 'Tonelada', 'allows_fraction' => true])->assertSessionHasNoErrors();
    $unit = UnitOfMeasure::query()->where('code', 'TON')->sole();
    Product::factory()->create(['company_id' => $company->id, 'unit_of_measure_id' => $unit->id]);

    $this->put("/products/units/{$unit->id}", ['code' => 'TON', 'name' => 'Tonelada', 'allows_fraction' => false])->assertSessionHasErrors('allows_fraction');
});

test('catalog records of another company respond 404', function (Closure $path) {
    actingAsProductsMember(Company::factory()->create());

    $this->put($path(), ['name' => 'X', 'code' => 'X'])->assertNotFound();
})->with([
    'category' => [fn () => '/products/categories/'.ProductCategory::factory()->create()->id],
    'brand' => [fn () => '/products/brands/'.ProductBrand::factory()->create()->id],
    'unit' => [fn () => '/products/units/'.UnitOfMeasure::factory()->create()->id],
]);

test('the lists render', function (string $path, string $component) {
    actingAsProductsMember(Company::factory()->create());

    $this->get($path)->assertInertia(fn (AssertableInertia $page) => $page->component($component));
})->with([
    ['/products/categories', 'products/categories'], ['/products/brands', 'products/brands'],
]);

test('a scanned code returns exactly one active sellable unit of the company', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = ProductVariant::factory()->for(Product::factory()->create(['company_id' => $company->id, 'name' => 'Caneta']))->create(['barcode' => '4006381333931']);
    ProductVariant::factory()->for(Product::factory()->inactive()->create(['company_id' => $company->id]))->create(['barcode' => '73513537']);
    ProductVariant::factory()->create(['barcode' => '036000291452']);

    $this->getJson('/products/lookup?term=4006381333931')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $variant->id);
    $this->getJson('/products/lookup?term=73513537')->assertJsonCount(0);
    $this->getJson('/products/lookup?term=036000291452')->assertJsonCount(0);
});

test('the internal catalog exposes active products, effective price and balance', function () {
    $company = Company::factory()->create();
    $variant = ProductVariant::factory()->for(Product::factory()->create(['company_id' => $company->id]))->create(['sale_price' => '10', 'promotional_price' => '8']);
    Product::factory()->inactive()->create(['company_id' => $company->id]);
    $catalog = app(ProductCatalog::class);

    expect($catalog->activeProducts($company)->count())->toBe(1);
    expect($catalog->pricesOf($variant))->toBe(['sale' => '10.00', 'promotional' => '8.00', 'effective' => '8.00']);
    expect($catalog->balanceOf($variant))->toBe('0.0000');
});
