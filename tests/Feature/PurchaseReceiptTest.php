<?php

use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Models\InventoryCostChange;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\AverageCostCalculator;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Parties\Models\Party;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Enums\ReceiptStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;

/**
 * Pedido confirmado pelo fluxo HTTP.
 *
 * @param  list<array{0: ProductVariant, 1: string, 2: string}>  $lines  Item, quantidade e custo unitário.
 * @param  array<string, mixed>  $overrides
 */
function httpConfirmedOrder(Company $company, array $lines, array $overrides = []): PurchaseOrder
{
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    test()->post('/purchases/orders', purchaseOrderData($supplier, array_map(fn (array $line): array => ['product_variant_id' => $line[0]->id, 'quantity' => $line[1], 'unit_cost' => $line[2]], $lines), [
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ]))->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    test()->post("/purchases/orders/{$order->id}/confirm")->assertSessionHasNoErrors();

    return $order->fresh();
}

/**
 * @param  array<int, string>  $quantities  Por posição do item.
 * @return array<string, mixed>
 */
function receiptRequest(PurchaseOrder $order, array $quantities, ?string $key = null): array
{
    return [
        'received_at' => now()->format('Y-m-d'),
        'document_reference' => 'NF 123',
        'idempotency_key' => $key ?? (string) Str::uuid(),
        'items' => $order->items()->get()->map(fn ($item): array => ['purchase_order_item_id' => $item->id, 'quantity' => $quantities[$item->position - 1] ?? '0'])->all(),
    ];
}

test('partial and total receipts move stock through InventoryService and update the status', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $service = purchasableVariant($company, isService: true);
    $order = httpConfirmedOrder($company, [[$variant, '10', '5.00'], [$service, '1', '20.00']]);

    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['4', '1']))->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
    expect($variant->balance->fresh()->quantity)->toBe('4.0000');
    $movement = InventoryMovement::query()->sole();
    $receipt = PurchaseReceipt::query()->sole();
    expect($movement)
        ->origin->toBe(MovementOrigin::Purchase)->reference_type->toBe('purchase_receipt')
        ->reference_id->toBe("{$receipt->id}:{$receipt->items()->where('product_variant_id', $variant->id)->sole()->id}")
        ->user_id->toBe($user->id)->idempotency_key->toStartWith('purchase-receipt-item:');
    expect($receipt->items()->where('product_variant_id', $service->id)->sole()->inventory_movement_id)->toBeNull();

    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['6']))->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);
    expect($variant->balance->fresh()->quantity)->toBe('10.0000');
    expect(SupplierProduct::query()->where('product_variant_id', $variant->id)->sole())->last_cost->toBe('5.0000');
});

test('rejects receiving more than pending, fractions in whole units and empty receipts', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $order = httpConfirmedOrder($company, [[purchasableVariant($company), '3', '1.00']]);

    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['3.0001']))->assertSessionHasErrors('items.0.quantity');
    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['1.5']))->assertSessionHasErrors('items.0.quantity');
    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['0']))->assertSessionHasErrors('items');

    expect(PurchaseReceipt::query()->count())->toBe(0);
    expect(InventoryMovement::query()->count())->toBe(0);
});

test('drafts and cancelled orders do not accept receipts', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $order = httpConfirmedOrder($company, [[purchasableVariant($company), '3', '1.00']]);
    $this->post("/purchases/orders/{$order->id}/cancel", ['reason' => 'Fornecedor sem estoque']);

    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['1']))->assertSessionHasErrors('items');
});

test('a repeated receipt request does not duplicate stock entries', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $order = httpConfirmedOrder($company, [[$variant, '10', '2.00']]);
    $data = receiptRequest($order, ['4']);

    $this->post("/purchases/orders/{$order->id}/receipts", $data)->assertSessionHasNoErrors();
    $this->post("/purchases/orders/{$order->id}/receipts", $data)->assertSessionHasNoErrors();
    $this->post("/purchases/orders/{$order->id}/receipts", [...$data, 'document_reference' => 'outro'])->assertSessionHasErrors('idempotency_key');

    expect(PurchaseReceipt::query()->count())->toBe(1);
    expect(InventoryMovement::query()->count())->toBe(1);
    expect($variant->balance->fresh()->quantity)->toBe('4.0000');
});

test('a failed inventory entry rolls back the whole receipt', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $first = purchasableVariant($company);
    $second = purchasableVariant($company);
    $order = httpConfirmedOrder($company, [[$first, '1', '1.00'], [$second, '1', '1.00']]);
    $this->app->instance(InventoryService::class, new class(app(AverageCostCalculator::class), $second->id) extends InventoryService
    {
        public function __construct(AverageCostCalculator $costs, private int $failingVariantId)
        {
            parent::__construct($costs);
        }

        public function recordEntry(StockOperation $operation): InventoryMovement
        {
            if ($operation->variantId === $this->failingVariantId) {
                throw new RuntimeException('Falha simulada no estoque.');
            }

            return parent::recordEntry($operation);
        }
    });

    $this->withoutExceptionHandling();
    expect(fn () => $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['1', '1'])))->toThrow(RuntimeException::class);

    expect(PurchaseReceipt::query()->count())->toBe(0);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed);
    expect($order->items()->pluck('received_quantity')->all())->toBe(['0.0000', '0.0000']);
    expect(InventoryMovement::query()->count())->toBe(0);
    expect($first->balance()->exists())->toBeFalse();
});

test('reversing a receipt restores stock, pending quantities and cost, preserving history', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $order = httpConfirmedOrder($company, [[$variant, '5', '10.00']]);
    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['5']));
    $receipt = PurchaseReceipt::query()->sole();

    $this->post("/products/movements/{$receipt->items()->sole()->inventory_movement_id}/reverse", ['reason' => 'Direto'])->assertSessionHasErrors('movement');
    $this->post("/purchases/receipts/{$receipt->id}/reverse", ['reason' => 'Lançado no pedido errado'])->assertSessionHasNoErrors();
    $this->post("/purchases/receipts/{$receipt->id}/reverse", ['reason' => 'De novo'])->assertSessionHasErrors('receipt');

    expect($receipt->fresh()->status)->toBe(ReceiptStatus::Reversed);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Confirmed);
    expect($variant->balance->fresh())->quantity->toBe('0.0000')->average_cost->toBeNull();
    expect(InventoryMovement::query()->count())->toBe(2);
    expect(InventoryCostChange::query()->count())->toBe(2);
});

test('returns cannot exceed the received quantity and respect non-negative stock', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $order = httpConfirmedOrder($company, [[$variant, '5', '10.00']]);
    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['5']));
    $receipt = PurchaseReceipt::query()->sole();
    $item = $receipt->items()->sole();
    $return = fn (string $quantity): array => ['returned_at' => now()->format('Y-m-d'), 'reason' => 'Avaria', 'idempotency_key' => (string) Str::uuid(), 'items' => [['purchase_receipt_item_id' => $item->id, 'quantity' => $quantity]]];

    $this->post("/purchases/receipts/{$receipt->id}/returns", $return('2'))->assertSessionHasNoErrors();
    $this->post("/purchases/receipts/{$receipt->id}/returns", $return('3.5'))->assertSessionHasErrors('items.0.quantity');

    app(InventoryService::class)->recordExit(new StockOperation($company->id, $variant->id, '3'));
    $this->post("/purchases/receipts/{$receipt->id}/returns", $return('1'))->assertSessionHasErrors();
    $this->post("/purchases/receipts/{$receipt->id}/reverse", ['reason' => 'Teste'])->assertSessionHasErrors('receipt');

    expect(PurchaseReturn::query()->count())->toBe(1);
    expect($item->fresh()->returned_quantity)->toBe('2.0000');
    expect($variant->balance->fresh()->quantity)->toBe('0.0000');
});

test('closing the pending balance finishes a partially received order', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $order = httpConfirmedOrder($company, [[purchasableVariant($company), '5', '1.00']]);
    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['2']));

    $this->post("/purchases/orders/{$order->id}/cancel", ['reason' => 'Teste'])->assertSessionHasErrors('status');
    $this->post("/purchases/orders/{$order->id}/close-balance", ['reason' => 'Fornecedor sem estoque'])->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(PurchaseOrderStatus::Received)->balance_closed_at->not->toBeNull();
    $this->post("/purchases/orders/{$order->id}/receipts", receiptRequest($order, ['1']))->assertSessionHasErrors('items');
});

test('quick purchase creates, confirms and receives atomically with a new supplier', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $data = purchaseOrderData(new SupplierProfile, [['product_variant_id' => $variant->id, 'quantity' => '3', 'unit_cost' => '4.00']], [
        'supplier_profile_id' => null,
        'new_supplier' => ['type' => 'individual', 'name' => 'Feira do Bairro'],
        'payment_condition' => 'cash',
        'payment_method' => 'pix',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $this->post('/purchases/quick', $data)->assertSessionHasNoErrors();
    $this->post('/purchases/quick', $data)->assertSessionHasNoErrors();

    $order = PurchaseOrder::query()->sole();
    expect($order)->status->toBe(PurchaseOrderStatus::Received)->is_quick->toBeTrue()->total->toBe('12.00');
    expect($order->supplier->party->name)->toBe('Feira do Bairro');
    expect($variant->balance->fresh())->quantity->toBe('3.0000')->average_cost->toBe('4.000000');
    expect(Party::query()->count())->toBe(1);
    expect(InventoryMovement::query()->count())->toBe(1);
});

test('quick purchase rolls back everything when it cannot be completed', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);

    $this->post('/purchases/quick', purchaseOrderData(new SupplierProfile, [['product_variant_id' => $variant->id, 'quantity' => '3', 'unit_cost' => '0']], [
        'supplier_profile_id' => null,
        'new_supplier' => ['type' => 'individual', 'name' => 'Fornecedor novo'],
        'idempotency_key' => (string) Str::uuid(),
    ]))->assertSessionHasErrors('items');

    expect(PurchaseOrder::query()->count())->toBe(0);
    expect(SupplierProfile::query()->count())->toBe(0);
    expect(Party::query()->count())->toBe(0);
});
