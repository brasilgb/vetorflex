<?php

use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockModule;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\PlatformModuleSeeder;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

/** Pedido de compra confirmado e recebido por completo. */
function receivedPurchase(Company $company, ProductVariant $variant, string $quantity): PurchaseOrder
{
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    test()->post('/purchases/orders', purchaseOrderData($supplier, [['product_variant_id' => $variant->id, 'quantity' => $quantity, 'unit_cost' => '4.00']], ['idempotency_key' => (string) Str::uuid()]))->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    test()->post("/purchases/orders/{$order->id}/confirm")->assertSessionHasNoErrors();
    test()->post("/purchases/orders/{$order->id}/receipts", [
        'received_at' => now()->format('Y-m-d'),
        'idempotency_key' => (string) Str::uuid(),
        'items' => $order->items()->get()->map(fn ($item): array => ['purchase_order_item_id' => $item->id, 'quantity' => $quantity])->all(),
    ])->assertSessionHasNoErrors();

    return $order->fresh();
}

test('purchases no longer depend on products and receive into stock when the stock module is enabled', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company, null, ['purchases']);
    $variant = stockItem($company);

    receivedPurchase($company, $variant, '6');

    expect(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('6.0000')
        ->and(InventoryMovement::query()->where('origin', 'purchase')->count())->toBe(1);
    $this->get('/products')->assertForbidden();
});

test('without any stock module, purchases register documents and costs but no stock movement', function () {
    $company = Company::factory()->create();
    $user = actingAsInventoryMember($company, null, ['purchases']);
    CompanyModule::query()->where('company_id', $company->id)->whereHas('module', fn ($query) => $query->where('identifier', 'inventory'))->update(['deactivated_at' => now()]);
    $variant = purchasableVariant($company, '4.00');

    $order = receivedPurchase($company, $variant, '3');

    expect($order->items()->sole()->received_quantity)->toBe('3.0000')
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('the purchases catalog entry declares no mandatory dependency', function () {
    $this->seed(PlatformModuleSeeder::class);

    expect(PlatformModule::query()->where('identifier', 'purchases')->sole())->dependencies->toBe([])
        ->optional_integrations->toContain('inventory')->toContain('products');
    expect(PlatformModule::query()->where('identifier', 'inventory')->sole())->dependencies->toBe([])->version->toBe('1.0.0')
        ->optional_integrations->toBe(['products', 'purchases', 'sales', 'pos', 'finance']);
});

test('with the stock module, sales and pos exit stock once, even with both enabled', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company, null, ['sales', 'pos']);
    $variant = stockItem($company, '10');
    $variant->update(['sale_price' => '5.00']);
    $session = openPosSession($company);

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['product_variant_id' => $variant->id, 'quantity' => '3']], [['method' => 'cash', 'amount' => '15.00']]))->assertCreated();

    expect(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('7.0000')
        ->and(InventoryMovement::query()->where('origin', 'sale')->count())->toBe(1)
        ->and(SaleOrder::query()->count())->toBe(1);
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['product_variant_id' => $variant->id, 'quantity' => '8']], [['method' => 'cash', 'amount' => '40.00']]))->assertUnprocessable();
});

test('a company that starts without stock keeps its history and defines an initial balance when it later enables the module', function () {
    $company = Company::factory()->create();
    $user = actingAsPosMember($company);
    $session = openPosSession($company);
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'Bolo', 'unit_price' => '20.00', 'quantity' => '1']], [['method' => 'cash', 'amount' => '20.00']]))->assertCreated();
    $variant = purchasableVariant($company);

    actingAsInventoryMember($company, user: $user);

    expect(InventoryMovement::query()->count())->toBe(0);
    $this->post('/inventory/movements', ['type' => 'initial', 'product_variant_id' => $variant->id, 'quantity' => '15', 'reason' => 'Contagem inicial', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $this->post('/inventory/movements', ['type' => 'initial', 'product_variant_id' => $variant->id, 'quantity' => '15', 'reason' => 'De novo', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('type');

    expect(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('15.0000')
        ->and(app(StockModule::class)->settings($company->refresh())['started_at'])->not->toBeNull()
        ->and(SaleOrder::query()->count())->toBe(1);
});

test('physical movements never write to finance tables', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company, null, ['finance']);
    $variant = stockItem($company, '5');

    $this->post('/inventory/movements', ['type' => 'exit', 'product_variant_id' => $variant->id, 'quantity' => '2', 'reason' => 'Perda', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

    expect(FinanceTitle::query()->count())->toBe(0)
        ->and(FinanceMovement::query()->count())->toBe(0);
});

test('a purchases-only company registers items on the order, controlling stock only when a stock module is enabled', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company, null, ['purchases']);
    CompanyModule::query()->where('company_id', $company->id)->whereHas('module', fn ($query) => $query->where('identifier', 'inventory'))->update(['deactivated_at' => now()]);

    $item = $this->postJson('/purchases/items', ['name' => 'Papel A4', 'type' => 'simple'])->assertCreated()->json();
    expect($item['controls_stock'])->toBeFalse();

    CompanyModule::query()->where('company_id', $company->id)->update(['deactivated_at' => null]);
    $tracked = $this->postJson('/purchases/items', ['name' => 'Toner', 'type' => 'simple', 'sku' => 'ton-1'])->assertCreated()->json();
    expect($tracked)->controls_stock->toBeTrue()->sku->toBe('TON-1');
    $this->postJson('/purchases/items', ['name' => 'Outro', 'type' => 'simple', 'sku' => 'TON-1'])->assertUnprocessable();
});
