<?php

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAttribute;
use App\Modules\Products\Models\ProductPriceChange;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;

/** @return array<string, mixed> */
function shirtAttributes(): array
{
    return ['attributes' => [['name' => 'Cor', 'values' => ['Azul', 'Preto']], ['name' => 'Tamanho', 'values' => ['P', 'M']]]];
}

test('a variable product starts without sellable units', function () {
    actingAsProductsMember(Company::factory()->create());

    $this->post('/products', ['type' => 'variable', 'name' => 'Camiseta'])->assertSessionHasNoErrors();

    expect(Product::query()->sole()->variants()->count())->toBe(0);
});

test('the preview lists combinations without saving', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->variable()->create(['company_id' => $company->id]);

    $this->postJson("/products/{$product->id}/variants/preview", shirtAttributes())
        ->assertOk()
        ->assertJson([['label' => 'Azul / P', 'exists' => false], ['label' => 'Azul / M'], ['label' => 'Preto / P'], ['label' => 'Preto / M']]);

    expect(ProductVariant::query()->count())->toBe(0);
    expect(ProductAttribute::query()->count())->toBe(0);
});

test('creates only confirmed combinations and never duplicates them', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->variable()->create(['company_id' => $company->id]);
    $url = "/products/{$product->id}/variants";

    $this->post($url, [...shirtAttributes(), 'labels' => ['Azul / P', 'Preto / M'], 'generate_sku' => true, 'sale_price' => '59.90'])->assertSessionHasNoErrors();

    expect($product->variants()->orderBy('id')->pluck('label')->all())->toBe(['Azul / P', 'Preto / M']);
    expect($product->variants()->whereNotNull('sku')->count())->toBe(2);
    expect($product->variants()->first()->attributeValues->pluck('value')->all())->toEqualCanonicalizing(['Azul', 'P']);
    expect(ProductPriceChange::query()->where('field', 'sale_price')->count())->toBe(2);

    $this->postJson("{$url}/preview", ['attributes' => [['name' => 'tamanho', 'values' => ['p']], ['name' => 'COR', 'values' => ['azul']]]])
        ->assertJson([['exists' => true]]);
    $this->post($url, [...shirtAttributes(), 'labels' => ['Azul / P']])->assertSessionHasErrors('attributes');

    expect($product->variants()->count())->toBe(2);
});

test('rejects invalid combinations', function (array $attributes) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->variable()->create(['company_id' => $company->id]);

    $this->postJson("/products/{$product->id}/variants/preview", ['attributes' => $attributes])->assertJsonValidationErrors('attributes');
})->with([
    'none' => [[]],
    'repeated attribute' => [[['name' => 'Cor', 'values' => ['Azul']], ['name' => 'cor', 'values' => ['Preto']]]],
    'too many' => [[['name' => 'A', 'values' => array_map(strval(...), range(1, 50))], ['name' => 'B', 'values' => ['1', '2', '3']]]],
]);

test('simple products do not accept combinations', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->postJson("/products/{$product->id}/variants/preview", shirtAttributes())->assertJsonValidationErrors('attributes');
});

test('updates a variation without touching its identity', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->variable()->create(['company_id' => $company->id]);
    $variant = ProductVariant::factory()->for($product)->create(['is_default' => false, 'label' => 'Azul', 'combination_key' => '1:1']);

    $this->put("/products/{$product->id}/variants/{$variant->id}", ['sku' => 'az-1', 'sale_price' => '30', 'is_active' => false, 'label' => 'Outro', 'combination_key' => 'x'])->assertSessionHasNoErrors();

    expect($variant->refresh())->sku->toBe('AZ-1')->sale_price->toBe('30.00')->is_active->toBeFalse()->label->toBe('Azul')->combination_key->toBe('1:1');
});

test('a variation must belong to the product in the url', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->variable()->create(['company_id' => $company->id]);
    $other = ProductVariant::factory()->for(Product::factory()->variable()->create(['company_id' => $company->id]))->create();

    $this->put("/products/{$product->id}/variants/{$other->id}", ['sale_price' => '1'])->assertNotFound();
});
