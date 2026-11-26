<?php

use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Enums\FulfillmentStatus;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Events\SaleFulfilled;
use App\Modules\Sales\Events\SaleReturnRegistered;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Support\SalePresenter;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Venda confirmada com os itens informados, no formato [unidade, quantidade]. */
function confirmedSale(Company $company, array $lines, array $overrides = []): SaleOrder
{
    test()->post('/sales/orders', saleOrderData(null, array_map(fn (array $line): array => ['product_variant_id' => $line[0]->id, 'quantity' => $line[1]], $lines), $overrides))->assertSessionHasNoErrors();
    $order = SaleOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    test()->post("/sales/orders/{$order->id}/confirm")->assertSessionHasNoErrors();

    return $order->refresh();
}

/** @param array<int, string> $quantities Quantidade por unidade comercializável. */
function fulfillSale(SaleOrder $order, array $quantities, ?string $key = null): TestResponse
{
    $items = $order->items()->get()->map(fn ($item): array => ['sale_order_item_id' => $item->id, 'quantity' => $quantities[$item->product_variant_id] ?? '0'])->all();

    return test()->post("/sales/orders/{$order->id}/fulfillments", [
        'fulfilled_at' => now()->format('Y-m-d'),
        'method' => 'pickup',
        'items' => $items,
        'idempotency_key' => $key ?? (string) Str::uuid(),
    ]);
}

function balanceOf(ProductVariant $variant): InventoryBalance
{
    return InventoryBalance::query()->where('product_variant_id', $variant->id)->firstOrFail();
}

test('partial fulfillment moves stock, consumes the reservation and records historical cost', function () {
    Event::fake([SaleFulfilled::class]);
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, '20.00', stock: '10', averageCost: '8.50');
    $order = confirmedSale($company, [[$variant, '6']]);

    fulfillSale($order, [$variant->id => '4'])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(SaleOrderStatus::PartiallyFulfilled);
    expect(balanceOf($variant))->quantity->toBe('6.0000')->reserved_quantity->toBe('2.0000');
    expect($order->fulfillments()->sole()->items()->sole())->revenue->toBe('80.00')->unit_cost->toBe('8.500000')->cost_total->toBe('34.00');
    expect(InventoryMovement::query()->where('origin', 'sale')->sole())->quantity->toBe('-4.0000');
    Event::assertDispatched(SaleFulfilled::class);

    fulfillSale($order, [$variant->id => '2'])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe(SaleOrderStatus::Fulfilled);
    expect(balanceOf($variant))->quantity->toBe('4.0000')->reserved_quantity->toBe('0.0000');
});

test('fulfillment above the pending quantity is refused and repeating the key does not duplicate stock exits', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '10');
    $order = confirmedSale($company, [[$variant, '3']]);

    fulfillSale($order, [$variant->id => '4'])->assertSessionHasErrors('items.0.quantity');
    $key = (string) Str::uuid();
    fulfillSale($order, [$variant->id => '1'], $key)->assertSessionHasNoErrors();
    fulfillSale($order, [$variant->id => '1'], $key)->assertSessionHasNoErrors();

    expect(SaleFulfillment::query()->count())->toBe(1);
    expect(balanceOf($variant)->quantity)->toBe('9.0000');
});

test('without average cost the cost is unavailable and the margin is not calculated as zero', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, '20.00', stock: '5');
    $order = confirmedSale($company, [[$variant, '1']]);
    fulfillSale($order, [$variant->id => '1']);

    expect($order->fulfillments()->sole()->cost_total)->toBeNull();
    expect(app(SalePresenter::class)->margin($order))->margin->toBeNull()->percent->toBeNull()->is_complete->toBeFalse();
});

test('margin uses the cost at fulfillment and is not recalculated by later cost changes', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, '20.00', stock: '10', averageCost: '10.00');
    $order = confirmedSale($company, [[$variant, '2']]);
    fulfillSale($order, [$variant->id => '2']);

    app(InventoryService::class)->recordEntry(new StockOperation($company->id, $variant->id, '8', entryValue: '400.00'));

    expect(app(SalePresenter::class)->margin($order->fresh()))->revenue->toBe('40.00')->cost->toBe('20.00')->margin->toBe('20.00')->percent->toBe('50.00');
});

test('closing the pending balance releases the remaining reservation', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '10');
    $order = confirmedSale($company, [[$variant, '5']]);
    fulfillSale($order, [$variant->id => '2']);

    $this->post("/sales/orders/{$order->id}/close-balance", ['reason' => 'Cliente levou só parte'])->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(SaleOrderStatus::Fulfilled)->balance_closed_at->not->toBeNull();
    expect(balanceOf($variant)->reserved_quantity)->toBe('0.0000');
});

test('technical reversal restores stock and the reservation', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '10');
    $order = confirmedSale($company, [[$variant, '3']]);
    fulfillSale($order, [$variant->id => '3']);
    $fulfillment = $order->fulfillments()->sole();

    $this->post("/sales/fulfillments/{$fulfillment->id}/reverse", ['reason' => 'Registrado por engano'])->assertSessionHasNoErrors();

    expect($fulfillment->fresh()->status)->toBe(FulfillmentStatus::Reversed);
    expect($order->fresh()->status)->toBe(SaleOrderStatus::Confirmed);
    expect(balanceOf($variant))->quantity->toBe('10.0000')->reserved_quantity->toBe('3.0000');
});

test('completed sales cannot be reversed and orders with fulfilled items cannot be cancelled', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '10');
    $order = confirmedSale($company, [[$variant, '2']]);
    fulfillSale($order, [$variant->id => '1']);

    $this->post("/sales/orders/{$order->id}/cancel", ['reason' => 'x'])->assertSessionHasErrors('status');
    fulfillSale($order, [$variant->id => '1']);
    $this->post("/sales/orders/{$order->id}/complete")->assertSessionHasNoErrors();

    $this->post("/sales/fulfillments/{$order->fulfillments()->first()->id}/reverse", ['reason' => 'x'])->assertSessionHasErrors(['fulfillment' => 'A venda está concluída. Registre uma devolução.']);
    expect($order->fresh()->status)->toBe(SaleOrderStatus::Completed);
});

test('partial return restocks at historical cost and refuses more than was fulfilled', function () {
    Event::fake([SaleReturnRegistered::class]);
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, '30.00', stock: '10', averageCost: '12.00');
    $order = confirmedSale($company, [[$variant, '3']]);
    fulfillSale($order, [$variant->id => '3']);
    $item = $order->fulfillments()->sole()->items()->sole();
    $returnTo = "/sales/fulfillments/{$item->sale_fulfillment_id}/returns";
    $data = fn (string $quantity): array => ['returned_at' => now()->format('Y-m-d'), 'reason' => 'Defeito', 'restock' => true, 'items' => [['sale_fulfillment_item_id' => $item->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()];

    $this->post($returnTo, $data('4'))->assertSessionHasErrors('items.0.quantity');
    $this->post($returnTo, $data('1'))->assertSessionHasNoErrors();

    $return = $order->returns()->sole();
    expect($return)->total_value->toBe('30.00')->cost_total->toBe('12.00');
    expect(balanceOf($variant)->quantity)->toBe('8.0000');
    expect($order->items()->sole()->returned_quantity)->toBe('1.0000');
    expect(app(SalePresenter::class)->margin($order->fresh()))->revenue->toBe('60.00')->cost->toBe('24.00');
    Event::assertDispatched(SaleReturnRegistered::class);

    $this->post($returnTo, $data('2'))->assertSessionHasNoErrors();
    $this->post($returnTo, $data('1'))->assertSessionHasErrors('items.0.quantity');
    expect($order->returns()->sum('total_value'))->toEqual(90);
});

test('return without restock keeps stock unchanged', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '10');
    $order = confirmedSale($company, [[$variant, '2']]);
    fulfillSale($order, [$variant->id => '2']);
    $item = $order->fulfillments()->sole()->items()->sole();

    $this->post("/sales/fulfillments/{$item->sale_fulfillment_id}/returns", ['returned_at' => now()->format('Y-m-d'), 'reason' => 'Avariado', 'restock' => false, 'items' => [['sale_fulfillment_item_id' => $item->id, 'quantity' => '1']], 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();

    expect(balanceOf($variant)->quantity)->toBe('8.0000');
});
