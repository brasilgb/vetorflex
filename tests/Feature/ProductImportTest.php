<?php

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;

function uploadProducts(string $contents): ProductImport
{
    test()->post('/products/imports', ['file' => UploadedFile::fake()->createWithContent('produtos.csv', $contents)])->assertSessionHasNoErrors();

    return ProductImport::query()->latest('id')->firstOrFail();
}

test('previews without saving and creates only after confirmation', function () {
    $company = Company::factory()->create();
    $user = actingAsProductsMember($company);
    $import = uploadProducts("\xEF\xBB\xBFnome;sku;categoria;marca;preco_custo;preco_venda;estoque_inicial\nCaneta;can-1;Papelaria > Escrita;Acme;1.234,5;2000,90;7\n");

    $this->get("/products/imports/{$import->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('products/import')->where('import.rows.0.status', 'new')->has('import.rows.0.warnings', 2));
    expect(Product::query()->count())->toBe(0);
    expect(ProductCategory::query()->count())->toBe(0);

    $this->post("/products/imports/{$import->id}/confirm")->assertRedirect("/products/imports/{$import->id}");

    $product = Product::query()->sole();
    expect($product)->company_id->toBe($company->id)->name->toBe('Caneta')->brand->name->toBe('Acme')->category->name->toBe('Escrita');
    expect($product->category->parent->name)->toBe('Papelaria');
    expect($product->variants()->sole())->sku->toBe('CAN-1')->cost_price->toBe('1234.5000')->sale_price->toBe('2000.90');
    expect(InventoryMovement::query()->sole())->type->value->toBe('initial')->quantity->toBe('7.0000')->user_id->toBe($user->id)->reference_id->toBe((string) $import->id);
    expect($import->refresh())->status->toBe('completed')->rows->toBeNull()->report->toMatchArray(['created' => 1, 'failed' => 0]);
});

test('confirming twice does not repeat products or movements', function () {
    actingAsProductsMember(Company::factory()->create());
    $import = uploadProducts("nome;estoque_inicial\nCaneta;7\n");

    $this->post("/products/imports/{$import->id}/confirm")->assertSessionHasNoErrors();
    $this->post("/products/imports/{$import->id}/confirm")->assertSessionHasNoErrors();

    expect(Product::query()->count())->toBe(1);
    expect(InventoryMovement::query()->count())->toBe(1);
});

test('existing products are only overwritten when confirmed and never have their stock changed', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $this->post('/products', ['type' => 'simple', 'name' => 'Caneta', 'sku' => 'CAN-1', 'sale_price' => '5', 'notes' => 'Manter', 'initial_stock' => '3']);
    $product = Product::query()->sole();
    $contents = "nome;sku;preco_venda;observacoes;estoque_inicial\nCaneta azul;can-1;6,00;;99\n";

    $unconfirmed = uploadProducts($contents);
    $this->get("/products/imports/{$unconfirmed->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('import.rows.0.status', 'duplicate')->where('import.rows.0.product_id', $product->id));
    $this->post("/products/imports/{$unconfirmed->id}/confirm");
    expect($product->refresh()->name)->toBe('Caneta');
    expect($unconfirmed->refresh()->report['skipped'])->toBe(1);

    $confirmed = uploadProducts($contents);
    $this->post("/products/imports/{$confirmed->id}/confirm", ['updates' => [['line' => 2, 'product_id' => $product->id]]])->assertSessionHasNoErrors();

    expect($product->refresh())->name->toBe('Caneta azul')->notes->toBe('Manter');
    expect($product->variants()->sole())->sale_price->toBe('6.00')->balance->quantity->toBe('3.0000');
    expect(InventoryMovement::query()->count())->toBe(1);
});

test('a confirmation for another product is refused', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id, 'name' => 'Caneta']);
    ProductVariant::factory()->for($product)->create(['sku' => 'CAN-1']);
    $import = uploadProducts("nome;sku\nOutro nome;CAN-1\n");

    $this->post("/products/imports/{$import->id}/confirm", ['updates' => [['line' => 2, 'product_id' => $product->id + 1]]]);

    expect($product->refresh()->name)->toBe('Caneta');
    expect($import->refresh()->report['skipped'])->toBe(1);
});

test('invalid rows are reported and do not block the valid ones', function (string $row, string $message) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    ProductBrand::factory()->inactive()->create(['company_id' => $company->id, 'name' => 'Inativa']);
    $variable = Product::factory()->variable()->create(['company_id' => $company->id, 'name' => 'Camiseta']);
    ProductVariant::factory()->for($variable)->create(['sku' => 'VAR-1', 'is_default' => false, 'label' => 'M']);
    $import = uploadProducts("nome;sku;codigo_barras;tipo_codigo;marca;unidade;preco_venda;preco_promocional;controla_estoque;estoque_inicial;tipo\nVálido;OK-1;;;;;;;;;\n{$row}\n");

    $this->post("/products/imports/{$import->id}/confirm")->assertSessionHasNoErrors();

    $report = $import->refresh()->report;
    expect($report)->toMatchArray(['created' => 1, 'failed' => 1]);
    expect($report['errors'][0])->line->toBe(3)->messages->toContain($message);
    $this->get("/products/imports/{$import->id}/errors")->assertOk()->assertDownload();
})->with([
    'missing name' => [';X;;;;;;;;;', 'Informe nome.'],
    'negative price' => ['A;X;;;;;-1;;;;', 'O campo preco_venda não pode ser negativo.'],
    'not a number' => ['A;X;;;;;abc;;;;', 'O campo preco_venda não é um número válido.'],
    'promotional above price' => ['A;X;;;;;5;6;;;', 'O preço promocional não pode ser maior que o preço de venda.'],
    'invalid gtin' => ['A;X;7891000315508;gtin;;;;;;;', 'Código GTIN/EAN/UPC inválido.'],
    'unknown unit' => ['A;X;;;;ZZ;;;;;', 'Unidade de medida não encontrada: ZZ.'],
    'inactive brand' => ['A;X;;;Inativa;;;;;;', 'Marca inativa: Inativa.'],
    'fraction in whole unit' => ['A;X;;;;;;;;1,5;', 'A unidade UN não aceita quantidade fracionária.'],
    'stock without control' => ['A;X;;;;;;;nao;5;', 'Estoque inicial informado para produto sem controle de estoque.'],
    'unknown boolean' => ['A;X;;;;;;;talvez;;', 'O campo controla_estoque deve ser "sim" ou "nao".'],
    'repeated in file' => ['A;ok-1;;;;;;;;;', 'Registro repetido no arquivo (linha 2).'],
    'variations' => ['A;X;;;;;;;;;Produto com variações', 'Somente produtos simples podem ser importados.'],
    'code of a variable product' => ['A;VAR-1;;;;;;;;;', 'O código já pertence a "Camiseta", que não é um produto simples.'],
    'extra columns' => ['A;X;;;;;;;;;;sobra', 'A linha tem mais colunas que o cabeçalho.'],
]);

test('rejects files that cannot be read', function (string $name, string $contents, string $message) {
    config(['products.import.maximum_rows' => 2, 'products.import.maximum_kilobytes' => 1]);
    actingAsProductsMember(Company::factory()->create());

    $this->post('/products/imports', ['file' => UploadedFile::fake()->createWithContent($name, $contents)])->assertSessionHasErrors(['file' => $message]);

    expect(ProductImport::query()->count())->toBe(0);
})->with([
    'without name column' => ['p.csv', "sku\nA\n", 'A coluna "nome" é obrigatória. Use o modelo de arquivo.'],
    'unknown column' => ['p.csv', "nome;company_id\nA;1\n", 'Colunas desconhecidas: company_id.'],
    'repeated column' => ['p.csv', "nome;nome\nA;B\n", 'O cabeçalho tem colunas repetidas.'],
    'empty' => ['p.csv', "nome\n", 'O arquivo não tem registros.'],
    'too many rows' => ['p.csv', "nome\nA\nB\nC\n", 'O arquivo excede o limite de 2 registros.'],
    'too large' => ['p.csv', "nome\n".str_repeat('A', 3000), 'O arquivo excede o tamanho máximo permitido.'],
    'wrong extension' => ['p.php', "nome\nA\n", 'Envie um arquivo CSV.'],
]);

test('reads windows-1252 files separated by comma', function () {
    actingAsProductsMember(Company::factory()->create());
    $import = uploadProducts((string) mb_convert_encoding("nome,preco_venda\nPão francês,\"1,50\"\n", 'Windows-1252', 'UTF-8'));

    $this->post("/products/imports/{$import->id}/confirm");

    expect(Product::query()->sole()->name)->toBe('Pão francês');
    expect(ProductVariant::query()->sole()->sale_price)->toBe('1.50');
});

test('ignores costs, prices and stock, and refuses new categories, without the permissions', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company, [ProductPermission::View, ProductPermission::Create, ProductPermission::Import]);
    $import = uploadProducts("nome;categoria;preco_custo;preco_venda;estoque_inicial\nCaneta;;1;2;3\nLápis;Nova;;;\n");

    $this->get("/products/imports/{$import->id}")->assertInertia(fn (AssertableInertia $page) => $page->missing('import.rows.0.attributes.cost_price'));
    $this->post("/products/imports/{$import->id}/confirm");

    expect(ProductVariant::query()->sole())->cost_price->toBeNull()->sale_price->toBeNull();
    expect(InventoryMovement::query()->count())->toBe(0);
    expect($import->refresh()->report['errors'][0]['messages'])->toBe(['Categoria não encontrada: Nova.']);
});

test('identifiers of another company are not duplicates and its imports respond 404', function () {
    $foreign = Company::factory()->create();
    actingAsProductsMember($foreign);
    $foreignImport = uploadProducts("nome\nSegredo\n");
    ProductVariant::factory()->for(Product::factory()->create(['company_id' => $foreign->id]))->create(['sku' => 'CAN-1']);
    $company = Company::factory()->create();
    actingAsProductsMember($company);

    $this->get("/products/imports/{$foreignImport->id}")->assertNotFound();
    $this->post("/products/imports/{$foreignImport->id}/confirm")->assertNotFound();
    $import = uploadProducts("nome;sku\nCaneta;CAN-1\n");
    $this->post("/products/imports/{$import->id}/confirm");

    expect(Product::query()->where('company_id', $company->id)->sole()->name)->toBe('Caneta');
});

test('another member of the company cannot open the import', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $import = uploadProducts("nome\nCaneta\n");
    actingAsProductsMember($company);

    $this->get("/products/imports/{$import->id}")->assertNotFound();
});

test('the template can be imported', function () {
    actingAsProductsMember(Company::factory()->create());
    $import = uploadProducts($this->get('/products/imports/template')->assertOk()->streamedContent());

    $this->get("/products/imports/{$import->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('import.rows.0.status', 'new'));
});
