<?php

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Products\Models\Product;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\PlatformModuleSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('sales is registered as implemented without commercial dependencies', function () {
    $this->seed(PlatformModuleSeeder::class);

    $sales = PlatformModule::query()->where('identifier', 'sales')->sole();
    expect($sales)->development_status->toBe('implemented')->version->toBe('1.0.0')->dependencies->toBe([])->commercially_available->toBeFalse();
    expect(array_column($sales->permissions, 'key'))->toBe(array_column(SalePermission::catalog(), 'key'));
});

test('a company with only sales registers a customer and an item and completes a sale without stock', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);

    $customer = $this->postJson('/sales/customers', ['type' => 'individual', 'name' => 'Maria Balcão', 'phone' => '11999990000'])->assertCreated()->json();
    $item = $this->postJson('/sales/items', ['name' => 'Caneca', 'type' => 'simple', 'sale_price' => '19.90'])->assertCreated()->json();
    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $item['id'], 'quantity' => '2']], ['customer_party_id' => $customer['id'], 'quick' => true]))->assertSessionHasNoErrors();

    expect(SaleOrder::query()->sole())->status->toBe(SaleOrderStatus::Completed)->total->toBe('39.80')->reserves_stock->toBeFalse();
    expect(Product::query()->sole()->tracks_inventory)->toBeFalse();
    expect(InventoryMovement::query()->count())->toBe(0);
    expect(InventoryReservation::query()->count())->toBe(0);
    $this->get('/products')->assertForbidden();
    $this->get('/customers')->assertForbidden();
});

test('without stock enabled, stock-controlled products are sold without hidden reservations or exits', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $variant = sellableVariant($company, stock: '1');

    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '3']], ['quick' => true]))->assertSessionHasNoErrors();

    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->first())->quantity->toBe('1.0000')->reserved_quantity->toBe('0.0000');
    expect(InventoryMovement::query()->count())->toBe(1);
});

test('the item lookup shows availability only with stock and cost only with permission', function () {
    $company = Company::factory()->create();
    $variant = sellableVariant($company, '10.00', stock: '7', averageCost: '4.00');
    $variant->product->update(['name' => 'Parafuso sextavado']);

    actingAsSalesMember($company, [SalePermission::View]);
    expect($this->getJson('/sales/items/lookup?term=Parafuso')->assertOk()->json('0'))->available->toBeNull()->average_cost->toBeNull()->price->toBe('10.00');

    actingAsSalesMember($company, [SalePermission::View, SalePermission::ViewCost], withStock: true);
    expect($this->getJson('/sales/items/lookup?term=Parafuso')->json('0'))->available->toBe('7.0000')->average_cost->toBe('4.000000');
});

test('a supplier becomes a customer only after confirmation and its data is not changed', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $party = $supplier->party;
    $party->update(['type' => 'company', 'document' => '11222333000181', 'name' => 'Fornecedor Original']);

    $this->postJson('/sales/customers', ['type' => 'company', 'name' => 'Outro nome', 'document' => '11.222.333/0001-81'])
        ->assertUnprocessable()->assertJsonValidationErrors('link_existing');
    $this->postJson('/sales/customers', ['type' => 'company', 'name' => 'Outro nome', 'document' => '11.222.333/0001-81', 'link_existing' => true])
        ->assertCreated()->assertJsonPath('id', $party->id);

    expect($party->fresh())->name->toBe('Fornecedor Original');
    expect($party->roles()->pluck('role')->sort()->values()->all())->toBe([PartyRole::CUSTOMER, PartyRole::SUPPLIER]);
});

test('the customer lookup finds customers by name, document and phone', function (string $term) {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    saleCustomer($company, ['name' => 'João Pereira', 'type' => 'individual', 'document' => '52998224725', 'phone' => '(11) 98888-7777']);
    saleCustomer($company, ['name' => 'Outra Pessoa']);

    expect($this->getJson('/sales/customers/lookup?term='.urlencode($term))->assertOk()->json())->toHaveCount(1)->{0}->name->toBe('João Pereira');
})->with(['Pereira', '529.982', '98888']);

test('sales operators do not receive other modules permissions and only grant what they have', function () {
    $company = Company::factory()->create();
    $owner = actingAsSalesMember($company, withStock: true);
    $operator = User::factory()->create();
    $operator->companies()->attach($company, ['role' => 'operator', 'is_active' => true, 'permissions' => []]);

    $this->put("/company/members/{$operator->id}/permissions", ['permissions' => ['sales.view', 'sales.view_cost']])->assertSessionHasNoErrors();

    expect($operator->companies()->sole()->pivot->permissions)->toBe(['sales.view', 'sales.view_cost']);
    $this->actingAs($operator)->withSession(['active_company_id' => $company->id]);
    $this->get('/sales')->assertOk();
    $this->get('/products')->assertForbidden();
    expect($owner)->not->toBeNull();
});

test('sale records of another company respond not found', function (string $method, string $path) {
    $other = Company::factory()->create();
    actingAsSalesMember($other);
    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => sellableVariant($other)->id, 'quantity' => '1']]));
    $order = SaleOrder::query()->sole();

    actingAsSalesMember(Company::factory()->create());
    $this->call($method, str_replace('{order}', (string) $order->id, $path), ['reason' => 'x'])->assertNotFound();
})->with([
    ['GET', '/sales/orders/{order}'],
    ['GET', '/sales/orders/{order}/document'],
    ['POST', '/sales/orders/{order}/confirm'],
    ['POST', '/sales/orders/{order}/cancel'],
]);

test('items and customers of another company cannot be used in a sale', function () {
    $other = Company::factory()->create();
    $foreignItem = sellableVariant($other);
    $foreignCustomer = saleCustomer($other);
    $company = Company::factory()->create();
    actingAsSalesMember($company);

    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $foreignItem->id, 'quantity' => '1']]))->assertSessionHasErrors('items.0.product_variant_id');
    $this->post('/sales/orders', saleOrderData($foreignCustomer, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']]))->assertSessionHasErrors('customer_party_id');
    expect(SaleOrder::query()->count())->toBe(0);
});

test('actions are refused without the matching permission', function (array $permissions, string $method, string $path) {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']]));
    $order = SaleOrder::query()->sole();
    actingAsSalesMember($company, $permissions);

    $this->call($method, str_replace('{order}', (string) $order->id, $path), ['reason' => 'x', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
})->with([
    'create' => [[SalePermission::View], 'POST', '/sales/orders'],
    'confirm' => [[SalePermission::View], 'POST', '/sales/orders/{order}/confirm'],
    'cancel' => [[SalePermission::View], 'POST', '/sales/orders/{order}/cancel'],
    'fulfill' => [[SalePermission::View], 'POST', '/sales/orders/{order}/fulfillments'],
    'export' => [[SalePermission::View], 'GET', '/sales/orders/export'],
    'register customer' => [[SalePermission::View], 'POST', '/sales/customers'],
    'register item' => [[SalePermission::View], 'POST', '/sales/items'],
    'module' => [[], 'GET', '/sales'],
]);

test('the order page hides costs and margins without permission', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, '20.00', stock: '5', averageCost: '5.00');
    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']], ['quick' => true]));
    $order = SaleOrder::query()->sole();

    actingAsSalesMember($company, [SalePermission::View, SalePermission::ViewCost]);
    $this->get("/sales/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('sales/orders/show')->where('order.margin', null)->where('fulfillments.0.cost_total', '5.00'));

    actingAsSalesMember($company, [SalePermission::View]);
    $this->get("/sales/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('order.margin', null)->where('fulfillments.0.cost_total', null)->missing('fulfillments.0.items.0.unit_cost'));

    actingAsSalesMember($company, [SalePermission::View, SalePermission::ViewCost, SalePermission::ViewMargin]);
    $this->get("/sales/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.margin.margin', '15.00')->where('order.margin.percent', '75.00'));
});

test('customer documents are PDFs without internal costs and the export protects formulas', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $customer = saleCustomer($company, ['name' => '=HYPERLINK("x")']);
    $variant = sellableVariant($company, '20.00', stock: '5', averageCost: '5.00');
    $this->post('/sales/orders', saleOrderData($customer, [['product_variant_id' => $variant->id, 'quantity' => '1']], ['quick' => true]));
    $order = SaleOrder::query()->sole();
    $fulfillment = $order->fulfillments()->sole();

    foreach (["/sales/orders/{$order->id}/document", "/sales/fulfillments/{$fulfillment->id}/document"] as $path) {
        $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF-1.4')->not->toContain('5,00');
    }

    $csv = $this->get('/sales/orders/export')->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->toContain('20,00');
});
