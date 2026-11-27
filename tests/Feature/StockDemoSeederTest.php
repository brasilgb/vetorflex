<?php

use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Inventory\Models\InventoryWarehouseBalance;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Database\Seeders\DatabaseSeeder;

test('the demo seed creates the stock scenarios once in the five module combinations', function () {
    $this->seed(DatabaseSeeder::class);
    $counts = fn (): array => [InventoryMovement::query()->count(), InventoryTransfer::query()->count(), InventoryCount::query()->count(), ProductVariant::query()->count()];
    $first = $counts();
    $this->seed(DatabaseSeeder::class);

    expect($counts())->toBe($first);

    $only = Company::query()->where('slug', 'deposito-somente-estoque')->sole();
    expect(InventoryTransfer::query()->where('company_id', $only->id)->count())->toBe(1)
        ->and(InventoryCount::query()->where('company_id', $only->id)->where('status', 'approved')->count())->toBe(1)
        ->and(InventoryMovement::query()->where('company_id', $only->id)->where('origin', 'count')->count())->toBe(1);

    foreach (['distribuidora-estoque-compras' => 'purchase', 'loja-estoque-vendas' => 'sale', 'mercearia-estoque-pdv' => 'sale'] as $slug => $origin) {
        $company = Company::query()->where('slug', $slug)->sole();
        expect(InventoryMovement::query()->where('company_id', $company->id)->where('origin', $origin)->count())->toBe(1);
    }

    $inventory = app(InventoryService::class);

    foreach (ProductVariant::query()->whereHas('product', fn ($query) => $query->where('tracks_inventory', true))->get() as $variant) {
        $warehouses = InventoryWarehouseBalance::query()->where('product_variant_id', $variant->id)->get()->reduce(fn (BigDecimal $carry, $row): BigDecimal => $carry->plus($row->quantity), BigDecimal::zero());
        expect((string) $warehouses->toScale(4))->toBe($inventory->balanceOf($variant->company_id, $variant->id));
    }

    $demo = Company::query()->where('slug', 'empresa-demonstracao')->sole();
    expect(InventoryTransfer::query()->where('company_id', $demo->id)->count())->toBe(1);
});
