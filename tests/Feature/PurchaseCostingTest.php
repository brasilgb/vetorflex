<?php

use App\Models\User;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Models\InventoryCostChange;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Purchases\Services\PurchaseReceiptService;
use App\Modules\Purchases\Services\PurchaseReturnService;
use App\Modules\Purchases\Support\PurchaseMath;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;

/**
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $overrides
 */
function confirmedOrder(Company $company, User $user, array $items, array $overrides = []): PurchaseOrder
{
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $order = app(PurchaseOrderService::class)->create($company, $user, purchaseOrderData($supplier, $items, $overrides), canManageCost: true);

    return app(PurchaseOrderService::class)->confirm($order, $user);
}

/** @param array<int, string> $quantities Quantidade por posição do item. */
function receiveOrder(PurchaseOrder $order, User $user, array $quantities): void
{
    app(PurchaseReceiptService::class)->receive($order, $user, [
        'received_at' => now()->format('Y-m-d'),
        'idempotency_key' => (string) Str::uuid(),
        'items' => $order->items()->get()->map(fn ($item): array => ['purchase_order_item_id' => $item->id, 'quantity' => $quantities[$item->position - 1] ?? '0'])->all(),
    ]);
}

test('allocates freight, insurance, expenses and discount proportionally with deterministic cents', function () {
    expect(PurchaseMath::allocate([
        ['total' => '10.00', 'quantity' => '1'],
        ['total' => '10.00', 'quantity' => '1'],
        ['total' => '10.00', 'quantity' => '1'],
    ], '10.00'))->toBe(['13.34', '13.33', '13.33']);

    expect(PurchaseMath::allocate([
        ['total' => '100.00', 'quantity' => '1'],
        ['total' => '0.00', 'quantity' => '5'],
        ['total' => '300.00', 'quantity' => '1'],
    ], '-40.00'))->toBe(['90.00', '0.00', '270.00']);
});

test('free items receive no allocation, unless every item is free', function () {
    expect(PurchaseMath::allocate([['total' => '0.00', 'quantity' => '1'], ['total' => '0.00', 'quantity' => '3']], '8.00'))
        ->toBe(['2.00', '6.00']);
});

test('confirmation fixes the acquisition cost of each item', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $first = purchasableVariant($company);
    $second = purchasableVariant($company);

    $order = confirmedOrder($company, $user, [
        ['product_variant_id' => $first->id, 'quantity' => '10', 'unit_cost' => '10.00', 'discount' => '0'],
        ['product_variant_id' => $second->id, 'quantity' => '5', 'unit_cost' => '20.00', 'discount' => '10.00'],
    ], ['freight' => '19.00', 'insurance' => '1.00', 'discount' => '10.00', 'payment_condition' => 'cash']);

    expect($order->total)->toBe('200.00');
    expect($order->items()->pluck('acquisition_total')->all())->toBe(['105.26', '94.74']);
    expect($order->paymentSchedules()->sole())->amount->toBe('200.00');
});

test('weighted moving average considers previous balance and acquisition cost', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $variant = purchasableVariant($company, costPrice: '8.00');
    app(InventoryService::class)->recordInitial(new StockOperation($company->id, $variant->id, '10'));

    $order = confirmedOrder($company, $user, [['product_variant_id' => $variant->id, 'quantity' => '10', 'unit_cost' => '11.00']], ['freight' => '10.00']);
    receiveOrder($order, $user, ['10']);

    // (10 × 8,00 + 120,00) / 20 = 10,00
    expect($variant->balance->fresh())->quantity->toBe('20.0000')->average_cost->toBe('10.000000');
    expect(InventoryCostChange::query()->sole())
        ->previous_cost->toBe('8.000000')->previous_cost_source->toBe(InventoryCostChange::SOURCE_REGISTERED)
        ->entry_value->toBe('120.00')->new_cost->toBe('10.000000');
    expect($variant->fresh()->cost_price)->toBe('8.0000');
});

test('first receipt with zero balance takes the acquisition cost', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);

    $order = confirmedOrder($company, $user, [['product_variant_id' => $variant->id, 'quantity' => '3', 'unit_cost' => '10.00']], ['other_costs' => '1.00']);
    receiveOrder($order, $user, ['3']);

    expect($variant->balance->fresh()->average_cost)->toBe('10.333333');
});

test('partial receipts allocate expenses once, closing the exact total', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);

    $order = confirmedOrder($company, $user, [['product_variant_id' => $variant->id, 'quantity' => '3', 'unit_cost' => '10.00']], ['freight' => '1.00']);
    receiveOrder($order, $user, ['1']);
    receiveOrder($order, $user, ['1']);
    receiveOrder($order, $user, ['1']);

    $values = $order->receipts()->pluck('total_cost')->all();
    expect($values)->toBe(['10.33', '10.34', '10.33']);
    expect($order->fresh()->status->value)->toBe('received');
});

test('return leaves the average cost and records the historical value', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $order = confirmedOrder($company, $user, [['product_variant_id' => $variant->id, 'quantity' => '4', 'unit_cost' => '10.00']], ['freight' => '2.00']);
    receiveOrder($order, $user, ['4']);
    $receipt = $order->receipts()->sole();

    $return = app(PurchaseReturnService::class)->register($receipt, $user, [
        'returned_at' => now()->format('Y-m-d'),
        'reason' => 'Avaria.',
        'idempotency_key' => (string) Str::uuid(),
        'items' => [['purchase_receipt_item_id' => $receipt->items()->sole()->id, 'quantity' => '1']],
    ]);

    expect($return->total_value)->toBe('10.50');
    expect($variant->balance->fresh())->quantity->toBe('3.0000')->average_cost->toBe('10.500000');
    expect($return->items()->sole()->stock_value)->toBe('10.50');
});
