<?php

use App\Models\User;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Services\PurchaseObligations;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\PlatformModuleSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/** @return array<string, mixed> */
function orderRequest(SupplierProfile $supplier, ProductVariant $variant, array $overrides = []): array
{
    return purchaseOrderData($supplier, [['product_variant_id' => $variant->id, 'quantity' => '4', 'unit_cost' => '12.50', 'discount' => '0']], [
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ]);
}

function storeOrder(Company $company, array $data): PurchaseOrder
{
    test()->post('/purchases/orders', $data)->assertSessionHasNoErrors();

    return PurchaseOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
}

test('creates a draft with sequential number, snapshots and no stock movement', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $variant = purchasableVariant($company);

    $first = storeOrder($company, orderRequest($supplier, $variant, ['freight' => '5.00']));
    $second = storeOrder($company, orderRequest($supplier, $variant));

    expect($first)->number->toBe(1)->status->toBe(PurchaseOrderStatus::Draft)->total->toBe('55.00')->user_id->toBe($user->id);
    expect($second->number)->toBe(2);
    expect($first->supplier_snapshot['name'])->toBe($supplier->party->name);
    expect($first->items()->sole())->description->toBe($variant->displayName())->total->toBe('50.00');
    expect(InventoryMovement::query()->count())->toBe(0);

    $supplier->party->update(['name' => 'Nome alterado']);
    $variant->product->update(['name' => 'Produto alterado']);
    expect($first->fresh()->supplier_snapshot['name'])->not->toBe('Nome alterado');
    expect($first->items()->sole()->description)->not->toContain('Produto alterado');
});

test('repeating the same creation request does not duplicate the order', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $data = orderRequest(SupplierProfile::factory()->create(['company_id' => $company->id]), purchasableVariant($company));

    $this->post('/purchases/orders', $data)->assertRedirect();
    $this->post('/purchases/orders', $data)->assertRedirect();
    $this->post('/purchases/orders', [...$data, 'notes' => 'Outro conteúdo'])->assertSessionHasErrors('idempotency_key');

    expect(PurchaseOrder::query()->count())->toBe(1);
});

test('validates installments against the order total', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $data = orderRequest(SupplierProfile::factory()->create(['company_id' => $company->id]), purchasableVariant($company), [
        'payment_condition' => 'entry_installments',
        'payment_method' => 'bank_slip',
        'installments' => [['due_date' => now()->format('Y-m-d'), 'amount' => '10.00'], ['due_date' => now()->addMonth()->format('Y-m-d'), 'amount' => '30.00']],
    ]);

    $this->post('/purchases/orders', $data)->assertSessionHasErrors('installments');

    $data['installments'][1]['amount'] = '40.00';
    $order = storeOrder($company, $data);

    expect($order->paymentSchedules()->get())
        ->toHaveCount(2)
        ->first()->is_entry->toBeTrue()
        ->and($order->paymentSchedules()->pluck('method')->map->value->all())->toBe(['bank_slip', 'bank_slip']);
});

test('state machine blocks invalid transitions', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $order = storeOrder($company, orderRequest(SupplierProfile::factory()->create(['company_id' => $company->id]), purchasableVariant($company)));

    $this->post("/purchases/orders/{$order->id}/close-balance", ['reason' => 'Teste'])->assertSessionHasErrors('status');
    $this->post("/purchases/orders/{$order->id}/confirm")->assertSessionHasNoErrors();
    $this->post("/purchases/orders/{$order->id}/confirm")->assertSessionHasErrors('status');
    $this->put("/purchases/orders/{$order->id}", orderRequest($order->supplier, purchasableVariant($company)))->assertSessionHasErrors('status');
    $this->post("/purchases/orders/{$order->id}/cancel", ['reason' => 'Desistência'])->assertSessionHasNoErrors();
    $this->post("/purchases/orders/{$order->id}/confirm")->assertSessionHasErrors('status');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
    expect($order->statusHistory()->pluck('to_status')->map->value->all())->toBe(['draft', 'confirmed', 'cancelled']);
    expect($order->statusHistory()->get()->last())->reason->toBe('Desistência');
    expect(InventoryMovement::query()->count())->toBe(0);
});

test('confirmation requires costs for items that are not free', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $order = storeOrder($company, purchaseOrderData($supplier, [['product_variant_id' => purchasableVariant($company)->id, 'quantity' => '1', 'unit_cost' => '0']], ['idempotency_key' => (string) Str::uuid()]));

    $this->post("/purchases/orders/{$order->id}/confirm")->assertSessionHasErrors('items');
});

test('inactive suppliers cannot receive new orders', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $supplier = SupplierProfile::factory()->inactive()->create(['company_id' => $company->id]);

    $this->post('/purchases/orders', orderRequest($supplier, purchasableVariant($company)))->assertSessionHasErrors('supplier_profile_id');
});

test('without manage_cost, costs are discarded and preserved on edit', function () {
    $company = Company::factory()->create();
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $variant = purchasableVariant($company);
    actingAsPurchasesMember($company);
    $order = storeOrder($company, orderRequest($supplier, $variant));

    actingAsPurchasesMember($company, [PurchasePermission::View, PurchasePermission::Create, PurchasePermission::Update]);
    $this->put("/purchases/orders/{$order->id}", orderRequest($supplier, $variant, [
        'freight' => '999',
        'items' => [['id' => $order->items()->sole()->id, 'product_variant_id' => $variant->id, 'quantity' => '8', 'unit_cost' => '0.01']],
    ]))->assertSessionHasNoErrors();

    expect($order->fresh())->freight->toBe('0.00')->total->toBe('100.00');
    expect($order->items()->sole())->unit_cost->toBe('12.5000')->quantity->toBe('8.0000');
});

test('values are hidden from members without view_cost', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $order = storeOrder($company, orderRequest(SupplierProfile::factory()->create(['company_id' => $company->id]), purchasableVariant($company)));

    actingAsPurchasesMember($company, [PurchasePermission::View, PurchasePermission::Export]);

    $this->get("/purchases/orders/{$order->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('purchases/orders/show')
        ->missing('order.total')->missing('order.freight')->missing('order.items.0.unit_cost')->where('order.payment_schedules', []));
    $this->get('/purchases/orders')->assertInertia(fn (AssertableInertia $page) => $page->missing('orders.data.0.total'));
    expect($this->get('/purchases/orders/export')->streamedContent())->not->toContain('total');
    expect($this->get("/purchases/orders/{$order->id}/document")->assertOk()->getContent())->not->toContain('Custo unit');
});

test('generates the purchase order PDF protected by tenant', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $order = storeOrder($company, orderRequest(SupplierProfile::factory()->create(['company_id' => $company->id]), purchasableVariant($company), ['notes' => 'Entregar pela manhã.']));

    $response = $this->get("/purchases/orders/{$order->id}/document")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF-1.4')->toContain('PEDIDO DE COMPRA')->toContain('Documento sem valor fiscal')->toContain('Total do pedido');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    actingAsPurchasesMember(Company::factory()->create());
    $this->get("/purchases/orders/{$order->id}/document")->assertNotFound();
});

test('isolates orders, suppliers and items between companies', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $otherSupplier = SupplierProfile::factory()->create(['company_id' => $other->id]);
    $otherVariant = purchasableVariant($other);
    actingAsPurchasesMember($other);
    $otherOrder = storeOrder($other, orderRequest($otherSupplier, $otherVariant));

    actingAsPurchasesMember($company);
    $ownSupplier = SupplierProfile::factory()->create(['company_id' => $company->id]);

    $this->get("/purchases/orders/{$otherOrder->id}")->assertNotFound();
    $this->post("/purchases/orders/{$otherOrder->id}/confirm")->assertNotFound();
    $this->get("/purchases/suppliers/{$otherSupplier->id}")->assertNotFound();
    $this->post('/purchases/orders', orderRequest($otherSupplier, purchasableVariant($company)))->assertSessionHasErrors('supplier_profile_id');
    $this->post('/purchases/orders', orderRequest($ownSupplier, $otherVariant))->assertSessionHasErrors('items.0.product_variant_id');
    $this->getJson('/purchases/items/lookup?term='.urlencode($otherVariant->product->name))->assertExactJson([]);
    $this->get('/purchases/orders')->assertInertia(fn (AssertableInertia $page) => $page->where('orders.total', 0));
});

test('requires the purchases module and individual permissions', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $this->get('/purchases')->assertForbidden();

    actingAsPurchasesMember($company, [PurchasePermission::View]);
    $this->get('/purchases')->assertOk();
    $this->get('/purchases/orders/create')->assertForbidden();
    $this->get('/purchases/quick')->assertForbidden();
    $this->get('/purchases/orders/export')->assertForbidden();
    $this->post('/purchases/suppliers', ['type' => 'company', 'name' => 'X'])->assertForbidden();
});

test('works without customers and finance, and purchases access does not grant products or customers', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company, [PurchasePermission::View, PurchasePermission::ViewCost]);

    expect(CompanyModule::query()->where('company_id', $company->id)->count())->toBe(2);
    $this->get('/purchases')->assertOk();
    $this->get('/customers')->assertForbidden();
    $this->get('/products')->assertForbidden();
});

test('rootadmin can only activate purchases after products', function () {
    $this->seed(PlatformModuleSeeder::class);
    $root = User::factory()->create(['is_root_admin' => true]);
    $company = Company::factory()->create();
    $purchases = PlatformModule::query()->where('identifier', 'purchases')->sole();

    expect($purchases)->development_status->toBe(PlatformModule::IMPLEMENTED)->version->toBe('1.0.0')->dependencies->toBe(['products']);
    expect(array_column($purchases->permissions, 'key'))->toContain('purchases.view_cost', 'purchases.import_suppliers');
    expect(CompanyModule::query()->where('platform_module_id', $purchases->id)->exists())->toBeFalse();

    $this->actingAs($root)->post("/rootadmin/companies/{$company->id}/modules/{$purchases->id}")->assertSessionHasErrors('module');

    $products = PlatformModule::query()->where('identifier', 'products')->sole();
    $this->actingAs($root)->post("/rootadmin/companies/{$company->id}/modules/{$products->id}");
    $this->actingAs($root)->post("/rootadmin/companies/{$company->id}/modules/{$purchases->id}")->assertSessionHasNoErrors();

    expect(CompanyModule::query()->where('company_id', $company->id)->where('platform_module_id', $purchases->id)->whereNull('deactivated_at')->exists())->toBeTrue();
});

test('obligation contract exposes confirmed orders without creating payables', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $order = storeOrder($company, orderRequest(SupplierProfile::factory()->create(['company_id' => $company->id]), purchasableVariant($company), ['payment_condition' => 'cash']));

    expect(app(PurchaseObligations::class)->obligationFor($order))->toBeNull();

    $this->post("/purchases/orders/{$order->id}/confirm");
    $obligation = app(PurchaseObligations::class)->obligationFor($order->fresh());

    expect($obligation)->amount->toBe('50.00')->receivedValue->toBe('0.00')->idempotencyKey->toBe("purchase-order:{$order->id}")
        ->and($obligation->installments)->toHaveCount(1);
});

test('products view cost permission is not required to read purchase values, and the reverse is not granted', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company, [PurchasePermission::View, PurchasePermission::ViewCost, ProductPermission::View]);
    $variant = purchasableVariant($company, costPrice: '3.00');

    $this->get("/products/{$variant->product_id}")->assertInertia(fn (AssertableInertia $page) => $page->missing('variants.0.cost_price')->missing('variants.0.average_cost'));
});
