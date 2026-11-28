<?php

use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleConfirmed;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Services\SaleReceivables;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

function storeSale(Company $company, array $data): SaleOrder
{
    test()->post('/sales/orders', $data)->assertSessionHasNoErrors();

    return SaleOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
}

test('creates a draft with sequential number, backend totals and snapshots', function () {
    $company = Company::factory()->create();
    $user = actingAsSalesMember($company);
    $customer = saleCustomer($company, ['name' => 'Cliente Original']);
    $variant = sellableVariant($company, '25.00');

    $first = storeSale($company, saleOrderData($customer, [['product_variant_id' => $variant->id, 'quantity' => '2', 'discount_percent' => '10', 'total' => '1.00']], [
        'freight' => '7.50', 'discount' => '5.00', 'items_total' => '1', 'total' => '1',
    ]));
    $second = storeSale($company, saleOrderData($customer, [['product_variant_id' => $variant->id, 'quantity' => '1']]));

    expect($first)->number->toBe(1)->status->toBe(SaleOrderStatus::Draft)->seller_id->toBe($user->id)
        ->items_total->toBe('45.00')->total->toBe('47.50');
    expect($first->items()->sole())->unit_price->toBe('25.00')->discount->toBe('5.00')->discount_percent->toBe('10.00')->total->toBe('45.00');
    expect($second->number)->toBe(2);
    expect(InventoryMovement::query()->count())->toBe(0);

    $customer->update(['name' => 'Nome alterado']);
    $variant->update(['sale_price' => '99.00']);
    expect($first->fresh()->customer_snapshot['name'])->toBe('Cliente Original');
    expect($first->items()->sole()->unit_price)->toBe('25.00');
});

test('repeating the creation request returns the same sale and different content is refused', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $data = saleOrderData(null, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']]);

    $this->post('/sales/orders', $data)->assertSessionHasNoErrors();
    $this->post('/sales/orders', $data)->assertSessionHasNoErrors();
    $this->post('/sales/orders', [...$data, 'notes' => 'outro'])->assertSessionHasErrors('idempotency_key');

    expect(SaleOrder::query()->count())->toBe(1);
});

test('without price and discount permissions the catalog price is used and discounts are discarded', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, [SalePermission::View, SalePermission::Create]);
    $variant = sellableVariant($company, '30.00');

    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '1.00', 'discount' => '29.00']], ['discount' => '10']));

    expect($order)->discount->toBe('0.00')->total->toBe('30.00');
    expect($order->items()->sole())->unit_price->toBe('30.00')->discount->toBe('0.00');
});

test('with price permission the seller sets the price and custom items need their own permission', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, [SalePermission::View, SalePermission::Create, SalePermission::ChangePrices]);
    $variant = sellableVariant($company, '30.00');

    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '27.90']]));
    expect($order->items()->sole())->unit_price->toBe('27.90')->catalog_price->toBe('30.00');

    $this->post('/sales/orders', saleOrderData(null, [['description' => 'Instalação', 'quantity' => '1', 'unit_price' => '50']]))
        ->assertSessionHasErrors(['items.0.product_variant_id' => 'Você não pode incluir itens avulsos. Selecione um item do catálogo.']);
});

test('custom items and services are sold without the catalog moving stock', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $service = sellableVariant($company, '80.00', isService: true);

    $order = storeSale($company, saleOrderData(null, [
        ['description' => 'Frete especial', 'unit_code' => 'srv', 'is_service' => true, 'quantity' => '1', 'unit_price' => '15'],
        ['product_variant_id' => $service->id, 'quantity' => '2'],
    ]));
    $this->post("/sales/orders/{$order->id}/confirm")->assertSessionHasNoErrors();

    expect($order->items()->get()->pluck('is_custom')->all())->toBe([true, false]);
    expect($order->fresh()->total)->toBe('175.00');
    expect(InventoryBalance::query()->where('reserved_quantity', '>', 0)->count())->toBe(0);
});

test('fractional quantities are refused for units without fraction', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);

    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1.5']]))
        ->assertSessionHasErrors('items.0.quantity');
});

test('an item without sale price cannot be sold at the catalog price', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, [SalePermission::View, SalePermission::Create]);
    $variant = purchasableVariant($company);
    $variant->update(['sale_price' => null]);

    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']]))
        ->assertSessionHasErrors('items.0.unit_price');
});

test('anonymous sales follow the company setting and delivery requires an address', function () {
    $company = Company::factory()->create(['settings' => ['sales' => ['allow_anonymous_customer' => false]]]);
    actingAsSalesMember($company);
    $variant = sellableVariant($company);

    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']]))
        ->assertSessionHasErrors(['customer_party_id' => 'Selecione o cliente.']);
    $this->post('/sales/orders', saleOrderData(saleCustomer($company), [['product_variant_id' => $variant->id, 'quantity' => '1']], ['delivery_method' => 'delivery']))
        ->assertSessionHasErrors(['delivery_address_id' => 'Informe o endereço de entrega.']);
});

test('installments must add up to the total', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $variant = sellableVariant($company, '100.00');

    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']], [
        'payment_condition' => 'installments',
        'installments' => [['due_date' => now()->addMonth()->format('Y-m-d'), 'amount' => '50'], ['due_date' => now()->addMonths(2)->format('Y-m-d'), 'amount' => '40']],
    ]))->assertSessionHasErrors('installments');

    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']], [
        'payment_condition' => 'entry_installments',
        'installments' => [['due_date' => now()->format('Y-m-d'), 'amount' => '40', 'method' => 'pix'], ['due_date' => now()->addMonth()->format('Y-m-d'), 'amount' => '60', 'method' => 'bank_slip']],
    ]));

    expect($order->paymentSchedules()->get()->map->only(['sequence', 'is_entry', 'amount'])->all())->toBe([
        ['sequence' => 1, 'is_entry' => true, 'amount' => '40.00'],
        ['sequence' => 2, 'is_entry' => false, 'amount' => '60.00'],
    ]);
});

test('confirmation reserves stock, allocates the general discount and publishes the event', function () {
    Event::fake([SaleConfirmed::class]);
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $first = sellableVariant($company, '10.00', stock: '10');
    $second = sellableVariant($company, '30.00', stock: '5');
    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $first->id, 'quantity' => '4'], ['product_variant_id' => $second->id, 'quantity' => '2']], ['discount' => '10.00']));

    $this->post("/sales/orders/{$order->id}/confirm")->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(SaleOrderStatus::Confirmed)->reserves_stock->toBeTrue();
    expect($order->items()->pluck('net_total')->all())->toBe(['36.00', '54.00']);
    expect(InventoryBalance::query()->where('product_variant_id', $first->id)->value('reserved_quantity'))->toBe('4.0000');
    expect(InventoryBalance::query()->where('product_variant_id', $first->id)->value('quantity'))->toBe('10.0000');
    expect(InventoryMovement::query()->where('origin', 'sale')->count())->toBe(0);
    Event::assertDispatched(SaleConfirmed::class);
});

test('confirmation is refused when stock is not available, considering other reservations', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '5');
    $first = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '4']]));
    $second = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '2']]));

    $this->post("/sales/orders/{$first->id}/confirm")->assertSessionHasNoErrors();
    $this->post("/sales/orders/{$second->id}/confirm")->assertSessionHasErrors('items');

    expect($second->fresh()->status)->toBe(SaleOrderStatus::Draft);
    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->value('reserved_quantity'))->toBe('4.0000');
});

test('manual stock exits cannot consume reserved quantities', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '5');
    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '4']]));
    $this->post("/sales/orders/{$order->id}/confirm");

    expect(fn () => app(InventoryService::class)->recordExit(new StockOperation($company->id, $variant->id, '2')))
        ->toThrow(ValidationException::class);
});

test('cancelling releases reservations, requires a reason and publishes the event', function () {
    Event::fake([SaleCancelled::class]);
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, stock: '5');
    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '3']]));
    $this->post("/sales/orders/{$order->id}/confirm");

    $this->post("/sales/orders/{$order->id}/cancel", ['reason' => ''])->assertSessionHasErrors(['reason' => 'Informe o motivo.']);
    $this->post("/sales/orders/{$order->id}/cancel", ['reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(SaleOrderStatus::Cancelled);
    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->value('reserved_quantity'))->toBe('0.0000');
    expect($order->statusHistory()->get()->last())->reason->toBe('Cliente desistiu')->to_status->toBe('cancelled');
    Event::assertDispatched(SaleCancelled::class);
});

test('only drafts can be edited', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $variant = sellableVariant($company);
    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']]));
    $this->post("/sales/orders/{$order->id}/confirm");

    $this->put("/sales/orders/{$order->id}", saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '2']]))
        ->assertSessionHasErrors(['status' => 'Somente vendas em rascunho podem ser editadas.']);
});

test('quick sale confirms, fulfills and completes atomically', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, '12.00', stock: '3', averageCost: '7.00');

    $order = storeSale($company, saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '2']], ['quick' => true, 'payment_condition' => 'cash', 'payment_method' => 'pix']));

    expect($order)->status->toBe(SaleOrderStatus::Completed)->is_quick->toBeTrue();
    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->first())->quantity->toBe('1.0000')->reserved_quantity->toBe('0.0000');
    expect($order->fulfillments()->sole())->revenue->toBe('24.00')->cost_total->toBe('14.00');

    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '5']], ['quick' => true]))->assertSessionHasErrors('items');
    expect(SaleOrder::query()->count())->toBe(1);
});

test('the receivable contract describes confirmed sales without creating financial records', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $customer = saleCustomer($company);
    $order = storeSale($company, saleOrderData($customer, [['product_variant_id' => sellableVariant($company, '100.00')->id, 'quantity' => '1']], [
        'payment_condition' => 'installments',
        'installments' => [['due_date' => now()->addMonth()->format('Y-m-d'), 'amount' => '50'], ['due_date' => now()->addMonths(2)->format('Y-m-d'), 'amount' => '50']],
    ]));
    $receivables = app(SaleReceivables::class);

    expect($receivables->receivableFor($order))->toBeNull();
    $this->post("/sales/orders/{$order->id}/confirm");
    $receivable = $receivables->receivableFor($order->fresh());

    expect($receivable)->amount->toBe('100.00')->customerPartyId->toBe($customer->id)->idempotencyKey->toBe("sale-order:{$order->id}");
    expect($receivable->installments)->toHaveCount(2);
    expect(Schema::hasTable('receivables'))->toBeFalse();
});
