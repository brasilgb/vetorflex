<?php

use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceTransfer;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Pos\Models\PosCashMovement;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Pos\Models\PosRefund;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\DatabaseSeeder;

test('the demo seed creates the pos scenarios once in the four module combinations', function () {
    $this->seed(DatabaseSeeder::class);
    $counts = fn (): array => [PosSession::query()->count(), PosSale::query()->count(), PosPayment::query()->count(), PosCashMovement::query()->count(), PosRefund::query()->count(), SaleOrder::query()->count()];
    $first = $counts();
    $this->seed(DatabaseSeeder::class);

    expect($counts())->toBe($first);

    foreach (['mercadinho-somente-pdv', 'papelaria-pdv-produtos', 'lanchonete-pdv-vendas', 'quiosque-pdv-financeiro'] as $slug) {
        $company = Company::query()->where('slug', $slug)->sole();
        $sessions = PosSession::query()->where('company_id', $company->id);
        expect((clone $sessions)->where('status', 'closed')->count())->toBe(1)
            ->and((clone $sessions)->where('status', 'open')->count())->toBe(1)
            ->and((clone $sessions)->where('status', 'closed')->sole()->divergence)->toBe('-2.00')
            ->and(PosSale::query()->where('company_id', $company->id)->where('status', 'completed')->count())->toBe(4)
            ->and(PosSale::query()->where('company_id', $company->id)->where('status', 'suspended')->count())->toBe(1)
            ->and(PosRefund::query()->where('company_id', $company->id)->count())->toBe(1)
            ->and(PosCashMovement::query()->where('company_id', $company->id)->whereIn('type', ['supply', 'withdrawal'])->count())->toBe(2);
    }

    $only = Company::query()->where('slug', 'mercadinho-somente-pdv')->sole();
    expect(InventoryMovement::query()->where('company_id', $only->id)->count())->toBe(0)
        ->and(FinanceMovement::query()->where('company_id', $only->id)->count())->toBe(0);

    $products = Company::query()->where('slug', 'papelaria-pdv-produtos')->sole();
    expect(InventoryMovement::query()->where('company_id', $products->id)->where('origin', 'sale')->exists())->toBeTrue();

    $finance = Company::query()->where('slug', 'quiosque-pdv-financeiro')->sole();
    expect(FinanceTransfer::query()->where('company_id', $finance->id)->count())->toBe(2);
});
