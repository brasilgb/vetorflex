<?php

use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\CountStatus;
use App\Modules\Inventory\Enums\InventoryPermission;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Tenancy\Models\Company;

beforeEach(fn () => $this->withoutVite());

test('a physical count compares with the warehouse balance and the approval generates one adjustment per divergence', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $short = stockItem($company, '10');
    $exact = stockItem($company, '4');
    $uncounted = stockItem($company, '7');
    $warehouse = app(WarehouseService::class)->defaultFor($company->id);

    $this->post('/inventory/counts', ['inventory_warehouse_id' => $warehouse->id, 'description' => 'Balanço mensal'])->assertSessionHasNoErrors();
    $count = InventoryCount::query()->sole();
    $items = $count->items()->pluck('id', 'product_variant_id');
    expect($items)->toHaveCount(3);

    $this->put("/inventory/counts/{$count->id}", ['counts' => [$items[$short->id] => '8', $items[$exact->id] => '4']])->assertSessionHasNoErrors();
    $this->get("/inventory/counts/{$count->id}")->assertInertia(fn ($page) => $page->component('inventory/counts/show')->where('items.0.system_quantity', '10.0000'));

    $this->post("/inventory/counts/{$count->id}/approve")->assertSessionHasNoErrors();
    $this->post("/inventory/counts/{$count->id}/approve")->assertSessionHasErrors('count');

    $inventory = app(InventoryService::class);
    expect($count->refresh()->status)->toBe(CountStatus::Approved)
        ->and($inventory->balanceOf($company->id, $short->id))->toBe('8.0000')
        ->and($inventory->balanceOf($company->id, $exact->id))->toBe('4.0000')
        ->and($inventory->balanceOf($company->id, $uncounted->id))->toBe('7.0000')
        ->and(InventoryMovement::query()->where('origin', 'count')->count())->toBe(1);
    $item = $count->items()->where('product_variant_id', $short->id)->sole();
    expect($item)->system_quantity->toBe('10.0000')->difference->toBe('-2.0000')->inventory_movement_id->not->toBeNull();

    $this->put("/inventory/counts/{$count->id}", ['counts' => [$items[$short->id] => '1']])->assertSessionHasErrors('count');
    $movement = InventoryMovement::query()->where('origin', 'count')->sole();
    $this->post("/inventory/movements/{$movement->id}/reverse", ['reason' => 'x'])->assertStatus(422);
});

test('the approval uses the balance at approval time, so movements during the count are respected', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company);
    $variant = stockItem($company, '10');
    $warehouse = app(WarehouseService::class)->defaultFor($company->id);
    $this->post('/inventory/counts', ['inventory_warehouse_id' => $warehouse->id, 'items' => [$variant->id]])->assertSessionHasNoErrors();
    $count = InventoryCount::query()->sole();
    $this->put("/inventory/counts/{$count->id}", ['counts' => [$count->items()->value('id') => '9']])->assertSessionHasNoErrors();

    app(InventoryService::class)->recordEntry(new StockOperation($company->id, $variant->id, '2'));
    $this->post("/inventory/counts/{$count->id}/approve")->assertSessionHasNoErrors();

    expect(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('9.0000')
        ->and($count->items()->sole()->system_quantity)->toBe('12.0000');
});

test('counting needs permission, approval needs its own permission and a cancelled count makes no adjustment', function () {
    $company = Company::factory()->create();
    actingAsInventoryMember($company, [InventoryPermission::View, InventoryPermission::Count]);
    $variant = stockItem($company, '5');
    $warehouse = app(WarehouseService::class)->defaultFor($company->id);

    $this->post('/inventory/counts', ['inventory_warehouse_id' => $warehouse->id])->assertSessionHasNoErrors();
    $count = InventoryCount::query()->sole();
    $this->put("/inventory/counts/{$count->id}", ['counts' => [$count->items()->value('id') => '2']])->assertSessionHasNoErrors();
    $this->post("/inventory/counts/{$count->id}/approve")->assertForbidden();
    $this->post("/inventory/counts/{$count->id}/cancel", ['reason' => 'Contagem refeita'])->assertSessionHasNoErrors();

    expect($count->refresh()->status)->toBe(CountStatus::Cancelled)
        ->and(app(InventoryService::class)->balanceOf($company->id, $variant->id))->toBe('5.0000');
});
