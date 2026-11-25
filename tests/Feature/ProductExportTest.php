<?php

use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;

test('exports only company products with formulas neutralized', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    ProductVariant::factory()->for(Product::factory()->create(['company_id' => $company->id, 'name' => '=1+1']))->create(['sku' => '@SUM', 'cost_price' => '4', 'sale_price' => '10']);
    ProductVariant::factory()->for(Product::factory()->create(['name' => 'De outra empresa']))->create();

    $content = $this->get('/products/export')->assertOk()->streamedContent();

    expect($content)->toContain("'=1+1")->toContain("'@SUM")->toContain('4,0000')->toContain('10,00')->not->toContain('De outra empresa');
});

test('applies the list filters', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    ProductVariant::factory()->for(Product::factory()->create(['company_id' => $company->id, 'name' => 'Caneta']))->create();
    ProductVariant::factory()->for(Product::factory()->inactive()->create(['company_id' => $company->id, 'name' => 'Papel']))->create();

    expect($this->get('/products/export?active=0')->streamedContent())->toContain('Papel')->not->toContain('Caneta');
});

test('omits costs and balances without permission', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company, [ProductPermission::View, ProductPermission::Export]);
    ProductVariant::factory()->for(Product::factory()->create(['company_id' => $company->id]))->create(['cost_price' => '77.7700']);

    expect($this->get('/products/export')->streamedContent())->not->toContain('preco_custo')->not->toContain('saldo')->not->toContain('77,77');
});

test('an exported file is recognized as existing products when imported', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $this->post('/products', ['type' => 'simple', 'name' => '-Caneta', 'sku' => 'CAN-1', 'cost_price' => '1.5', 'sale_price' => '2', 'initial_stock' => '3']);
    $content = $this->get('/products/export')->streamedContent();

    $this->followingRedirects()->post('/products/imports', ['file' => UploadedFile::fake()->createWithContent('produtos.csv', $content)])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('import.rows.0.status', 'duplicate')->where('import.rows.0.name', '-Caneta')->where('import.rows.0.errors', []));
});
