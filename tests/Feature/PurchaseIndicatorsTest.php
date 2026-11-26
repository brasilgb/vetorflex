<?php

use App\Modules\Inventory\Models\InventoryCostChange;
use App\Modules\Parties\Models\Party;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchasePaymentSchedule;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\CustomerDemoSeeder;
use Database\Seeders\PlatformModuleSeeder;
use Database\Seeders\ProductDemoSeeder;
use Database\Seeders\PurchaseDemoSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('indicators use real orders, receipts and returns, separating ordered and received values', function () {
    $this->travelTo(now()->startOfMonth()->addDays(14));
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $create = function (string $quantity, string $cost, ?string $expected = null) use ($supplier, $variant): PurchaseOrder {
        $this->post('/purchases/orders', purchaseOrderData($supplier, [['product_variant_id' => $variant->id, 'quantity' => $quantity, 'unit_cost' => $cost]], ['idempotency_key' => (string) Str::uuid(), 'expected_date' => $expected, 'order_date' => $expected === null ? now()->format('Y-m-d') : now()->subDays(3)->format('Y-m-d')]));
        $order = PurchaseOrder::query()->latest('id')->firstOrFail();
        $this->post("/purchases/orders/{$order->id}/confirm");

        return $order;
    };

    $first = $create('10', '2.00');
    $this->post("/purchases/orders/{$first->id}/receipts", ['received_at' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $first->items()->sole()->id, 'quantity' => '10']]]);
    $second = $create('5', '3.00', now()->subDay()->format('Y-m-d'));
    $this->post("/purchases/orders/{$second->id}/receipts", ['received_at' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid(), 'items' => [['purchase_order_item_id' => $second->items()->sole()->id, 'quantity' => '2']]]);
    $this->post('/purchases/orders', purchaseOrderData($supplier, [['product_variant_id' => $variant->id, 'quantity' => '1', 'unit_cost' => '9']], ['idempotency_key' => (string) Str::uuid()]));

    $this->get('/purchases')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('purchases/dashboard')
        ->where('indicators.orders_count', 2)
        ->where('indicators.ordered_value', '35.00')
        ->where('indicators.received_value', '26.00')
        ->where('indicators.confirmed_open_value', '15.00')
        ->where('indicators.pending_count', 1)
        ->where('indicators.late_count', 1)
        ->where('indicators.draft_count', 1)
        ->where('indicators.top_suppliers.0.value', '35.00')
        ->where('indicators.top_products.0.quantity', '12.0000')
        ->where('indicators.cost_changes.0.previous', '2.0000')
        ->where('indicators.cost_changes.0.current', '3.0000')
        ->where('indicators.cost_changes.0.variation', '50.00')
        ->has('indicators.monthly', 12));

    actingAsPurchasesMember($company, [PurchasePermission::View]);
    $this->get('/purchases')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('indicators.orders_count', 2)
        ->missing('indicators.ordered_value')->missing('indicators.monthly')->missing('indicators.top_suppliers.0.value')->missing('indicators.top_products.0.value'));
});

test('demo seed builds every purchase scenario through the services and is idempotent', function () {
    $this->seed([PlatformModuleSeeder::class, CustomerDemoSeeder::class, ProductDemoSeeder::class]);
    (new PurchaseDemoSeeder)->run();
    $this->travel(2)->days();
    (new PurchaseDemoSeeder)->run();

    $statuses = PurchaseOrder::query()->pluck('status')->map->value->countBy()->sortKeys()->all();
    expect($statuses)->toBe(['confirmed' => 1, 'draft' => 1, 'partially_received' => 1, 'received' => 2]);
    expect(SupplierProfile::query()->count())->toBe(4);
    expect(Party::query()->where('document', '11222333000181')->sole()->roles()->count())->toBe(2);
    expect(PurchaseReturn::query()->count())->toBe(1);
    expect(PurchasePaymentSchedule::query()->count())->toBeGreaterThanOrEqual(5);
    expect(InventoryCostChange::query()->count())->toBeGreaterThan(0);
    expect(SupplierProduct::query()->where('product_variant_id', SupplierProduct::query()->where('supplier_code', 'PA-CAD-96')->value('product_variant_id'))->count())->toBe(2);
});
