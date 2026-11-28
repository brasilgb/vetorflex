<?php

use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\DatabaseSeeder;

test('the demo seed creates every sales scenario once, including a sales-only company', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $demo = Company::query()->where('slug', 'empresa-demonstracao')->sole();
    $salesOnly = Company::query()->where('slug', 'loja-somente-vendas')->sole();

    expect(SaleQuote::query()->where('company_id', $demo->id)->pluck('status')->all())->toEqualCanonicalizing([SaleQuoteStatus::Issued, SaleQuoteStatus::Converted]);
    expect(SaleOrder::query()->where('company_id', $demo->id)->pluck('status')->map->value->sort()->values()->all())
        ->toBe(['cancelled', 'completed', 'confirmed', 'draft', 'fulfilled', 'partially_fulfilled']);
    expect(SaleReturn::query()->where('company_id', $demo->id)->count())->toBe(1);
    expect(SaleOrder::query()->where('company_id', $salesOnly->id)->sole())->status->toBe(SaleOrderStatus::Completed)->reserves_stock->toBeFalse();
});
