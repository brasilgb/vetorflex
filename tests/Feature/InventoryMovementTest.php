<?php

use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

function stockedVariant(Company $company, string $quantity = '10', bool $isFractional = false): ProductVariant
{
    $unit = UnitOfMeasure::factory()->create(['company_id' => $company->id, 'allows_fraction' => $isFractional]);
    $variant = ProductVariant::factory()->for(Product::factory()->create(['company_id' => $company->id, 'unit_of_measure_id' => $unit->id]))->create();
    app(InventoryService::class)->recordInitial(new StockOperation($company->id, $variant->id, $quantity));

    return $variant;
}

/** @return array<string, mixed> */
function movementData(ProductVariant $variant, string $type, string $quantity): array
{
    return ['product_variant_id' => $variant->id, 'type' => $type, 'quantity' => $quantity, 'reason' => 'Conferência', 'idempotency_key' => (string) Str::uuid()];
}

test('each type changes the balance and preserves the history', function (string $type, string $quantity, string $delta, string $balance) {
    $company = Company::factory()->create();
    $user = actingAsProductsMember($company);
    $variant = stockedVariant($company);

    $this->post('/products/movements', movementData($variant, $type, $quantity))->assertSessionHasNoErrors();

    expect(InventoryMovement::query()->latest('id')->first())
        ->company_id->toBe($company->id)->type->value->toBe($type)->quantity->toBe($delta)
        ->balance_before->toBe('10.0000')->balance_after->toBe($balance)
        ->origin->toBe(MovementOrigin::Manual)->user_id->toBe($user->id)->reason->toBe('Conferência');
    expect($variant->balance->quantity)->toBe($balance);
})->with([
    'entry' => ['entry', '5', '5.0000', '15.0000'],
    'exit' => ['exit', '10', '-10.0000', '0.0000'],
    'adjustment down' => ['adjustment', '4', '-6.0000', '4.0000'],
    'adjustment to zero' => ['adjustment', '0', '-10.0000', '0.0000'],
]);

test('rejects movements that break stock rules', function (string $type, string $quantity, string $field) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = stockedVariant($company);

    $this->post('/products/movements', movementData($variant, $type, $quantity))->assertSessionHasErrors($field);

    expect(InventoryMovement::query()->count())->toBe(1);
    expect($variant->balance->quantity)->toBe('10.0000');
})->with([
    'negative stock' => ['exit', '10.0001', 'quantity'],
    'fraction in whole unit' => ['entry', '1.5', 'quantity'],
    'zero entry' => ['entry', '0', 'quantity'],
    'negative quantity' => ['entry', '-1', 'quantity'],
    'adjustment without difference' => ['adjustment', '10', 'quantity'],
    'second initial stock' => ['initial', '1', 'type'],
    'direct reversal' => ['reversal', '1', 'type'],
]);

test('accepts decimal quantities without rounding errors', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = stockedVariant($company, '0.1', isFractional: true);

    $this->post('/products/movements', movementData($variant, 'entry', '0.2'))->assertSessionHasNoErrors();

    expect($variant->balance->quantity)->toBe('0.3000');
});

test('an adjustment requires a reason', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = stockedVariant($company);

    $this->post('/products/movements', [...movementData($variant, 'adjustment', '1'), 'reason' => ''])->assertSessionHasErrors('reason');
});

test('repeating an idempotency key does not duplicate the movement', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = stockedVariant($company);
    $data = movementData($variant, 'entry', '5');

    $this->post('/products/movements', $data)->assertSessionHasNoErrors();
    $this->post('/products/movements', $data)->assertSessionHasNoErrors();
    $this->post('/products/movements', [...$data, 'quantity' => '6'])->assertSessionHasErrors('idempotency_key');

    expect($variant->balance->quantity)->toBe('15.0000');
    expect(InventoryMovement::query()->count())->toBe(2);
});

test('services, untracked and inactive items are not movable', function (array $attributes) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = ProductVariant::factory()->for(Product::factory()->create(['company_id' => $company->id, ...$attributes]))->create();

    $this->post('/products/movements', movementData($variant, 'entry', '1'))->assertSessionHasErrors('product_variant_id');

    expect(InventoryBalance::query()->count())->toBe(0);
})->with([
    'service' => [['type' => 'service']],
    'untracked' => [['tracks_inventory' => false]],
    'inactive' => [['is_active' => false]],
]);

test('a reversal compensates once and cannot itself be reversed', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = stockedVariant($company);
    $exit = app(InventoryService::class)->recordExit(new StockOperation($company->id, $variant->id, '4', MovementOrigin::Sale, referenceType: 'sale', referenceId: '77'));

    $this->post("/products/movements/{$exit->id}/reverse", ['reason' => 'Cancelada'])->assertSessionHasNoErrors();
    $reversal = InventoryMovement::query()->where('reversal_of_id', $exit->id)->sole();
    $this->post("/products/movements/{$exit->id}/reverse", ['reason' => 'De novo'])->assertSessionHasErrors('movement');
    $this->post("/products/movements/{$reversal->id}/reverse", ['reason' => 'Desfazer'])->assertSessionHasErrors('movement');

    expect($reversal)->quantity->toBe('4.0000')->origin->toBe(MovementOrigin::Sale)->reference_id->toBe('77');
    expect($exit->refresh()->quantity)->toBe('-4.0000');
    expect($variant->balance->quantity)->toBe('10.0000');
});

test('a reversal cannot make the stock negative', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $variant = stockedVariant($company);
    $initial = InventoryMovement::query()->sole();
    app(InventoryService::class)->recordExit(new StockOperation($company->id, $variant->id, '1'));

    $this->post("/products/movements/{$initial->id}/reverse", ['reason' => 'Erro'])->assertSessionHasErrors('quantity');

    expect($variant->balance->quantity)->toBe('9.0000');
});

test('movements are immutable', function () {
    stockedVariant(Company::factory()->create());
    $movement = InventoryMovement::query()->sole();

    expect(fn () => $movement->update(['quantity' => '1']))->toThrow(LogicException::class);
    expect(fn () => $movement->delete())->toThrow(LogicException::class);
});

test('items and movements of another company are out of reach', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $foreign = stockedVariant(Company::factory()->create());
    $movement = InventoryMovement::query()->sole();

    $this->post('/products/movements', movementData($foreign, 'entry', '1'))->assertSessionHasErrors('product_variant_id');
    $this->post("/products/movements/{$movement->id}/reverse", ['reason' => 'X'])->assertNotFound();
    $this->get('/products/movements')->assertInertia(fn (AssertableInertia $page) => $page->component('products/movements')->has('movements.data', 0));
    expect(fn () => app(InventoryService::class)->recordEntry(new StockOperation($company->id, $foreign->id, '1')))->toThrow(ModelNotFoundException::class);
});

test('viewing the inventory does not allow moving or reversing', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company, [ProductPermission::View, ProductPermission::ViewInventory]);
    $variant = stockedVariant($company);
    $movement = InventoryMovement::query()->sole();

    $this->get('/products/movements')->assertOk();
    $this->post('/products/movements', movementData($variant, 'entry', '1'))->assertForbidden();
    $this->post("/products/movements/{$movement->id}/reverse", ['reason' => 'X'])->assertForbidden();

    expect(InventoryMovement::query()->count())->toBe(1);
});

test('filters the history by item, type and company day', function (string $query, int $count) {
    $company = Company::factory()->create(['timezone' => 'America/Sao_Paulo']);
    actingAsProductsMember($company);
    $this->travelTo('2026-11-26 01:00:00');
    $variant = stockedVariant($company);
    $variant->update(['sku' => 'AB-1']);

    $this->get("/products/movements?{$query}")->assertInertia(fn (AssertableInertia $page) => $page->has('movements.data', $count));
})->with([
    'sku' => ['search=AB-1', 1],
    'other sku' => ['search=ZZ', 0],
    'type' => ['type=exit', 0],
    'local day' => ['from=2026-11-25&until=2026-11-25', 1],
    'utc day' => ['from=2026-11-26', 0],
]);

test('the service rejects malformed quantities from internal callers', function (string $quantity) {
    $company = Company::factory()->create();
    $variant = stockedVariant($company, isFractional: true);

    expect(fn () => app(InventoryService::class)->recordEntry(new StockOperation($company->id, $variant->id, $quantity)))->toThrow(ValidationException::class);
})->with(['abc', '', '0.00001', '-1']);
