<?php

use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Models\FinanceTransfer;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\PlatformModuleSeeder;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

/** Empresa com PDV e Financeiro, contas configuradas para dinheiro e Pix e para a sangria. */
function posWithFinance(): array
{
    $company = Company::factory()->create();
    actingAsPosMember($company, alsoEnable: ['finance']);
    $drawer = financeAccount($company, '0.00', 'Gaveta');
    $pix = financeAccount($company, '0.00', 'Conta Pix');
    $safe = financeAccount($company, '0.00', 'Cofre');
    $company->update(['settings' => ['pos' => ['finance_accounts' => ['cash' => $drawer->id, 'pix' => $pix->id], 'cash_account_id' => $drawer->id, 'treasury_account_id' => $safe->id]]]);

    return [$company->refresh(), $drawer, $pix, $safe];
}

test('with finance, received payments are settled in the configured accounts and term values stay open', function () {
    [$company, $drawer, $pix] = posWithFinance();
    $session = openPosSession($company, '80.00');
    $customer = saleCustomer($company);
    expect(FinanceMovement::query()->count())->toBe(0);

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart(
        [['description' => 'Conserto', 'unit_price' => '150.00', 'quantity' => '1']],
        [['method' => 'pix', 'amount' => '50.00'], ['method' => 'credit_card', 'amount' => '30.00'], ['method' => 'other', 'amount' => '40.00', 'is_term' => true, 'due_date' => now()->addMonth()->format('Y-m-d')], ['method' => 'cash', 'amount' => '40.00']],
        ['customer_party_id' => $customer->id],
    ))->assertCreated();

    $titles = FinanceTitle::query()->where('origin', TitleOrigin::Sale)->orderBy('due_date')->orderBy('id')->get();
    expect($titles)->toHaveCount(4);
    expect($titles->sum(fn ($title) => (float) $title->amount))->toEqual(150.0);
    expect(FinanceSettlement::query()->count())->toBe(2);
    $ledger = app(FinanceLedger::class);
    expect($ledger->balanceOf($pix))->toBe('50.00')->and($ledger->balanceOf($drawer))->toBe('30.00');
    expect($titles->where('status', TitleStatus::Pending)->count())->toBe(2);
    expect(FinanceTitle::query()->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])->sum('amount'))->toEqual('70.00');
});

test('with finance, withdrawals and supplies become transfers, never revenue or expense', function () {
    [$company, $drawer, , $safe] = posWithFinance();
    $session = openPosSession($company, '200.00');

    $this->postJson("/pos/sessions/{$session->id}/cash-movements", ['type' => 'withdrawal', 'amount' => '120.00', 'reason' => 'Sangria para o cofre', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    $this->postJson("/pos/sessions/{$session->id}/cash-movements", ['type' => 'supply', 'amount' => '20.00', 'reason' => 'Troco', 'idempotency_key' => (string) Str::uuid()])->assertCreated();

    expect(FinanceTransfer::query()->count())->toBe(2);
    expect(FinanceMovement::query()->where('is_operational', true)->count())->toBe(0);
    expect(app(FinanceLedger::class)->balanceOf($safe))->toBe('100.00');
    expect(FinanceTitle::query()->count())->toBe(0);
});

test('with finance, a cash refund after a return settles the refund title', function () {
    [$company, $drawer] = posWithFinance();
    $session = openPosSession($company, '0.00');
    $saleId = $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'Camiseta', 'unit_price' => '60.00', 'quantity' => '2']], [['method' => 'cash', 'amount' => '120.00']]))->assertCreated()->json('sale.id');
    $item = SaleOrder::query()->sole()->items()->sole();

    $this->post("/pos/sales/{$saleId}/returns", ['items' => [['sale_order_item_id' => $item->id, 'quantity' => '1']], 'reason' => 'Tamanho errado', 'refund_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

    $refund = FinanceTitle::query()->where('origin', TitleOrigin::SaleRefund)->sole();
    expect($refund)->amount->toBe('60.00')->status->toBe(TitleStatus::Settled);
    expect(app(FinanceLedger::class)->balanceOf($drawer))->toBe('60.00');
});

test('without finance the POS keeps its own cash control and writes nothing financial', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company, '10.00');

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'Pão', 'unit_price' => '8.00', 'quantity' => '1']], [['method' => 'cash', 'amount' => '10.00']]))->assertCreated();
    $this->postJson("/pos/sessions/{$session->id}/cash-movements", ['type' => 'withdrawal', 'amount' => '5.00', 'reason' => 'Sangria', 'idempotency_key' => (string) Str::uuid()])->assertCreated();

    expect(FinanceTitle::query()->count())->toBe(0);
    expect(FinanceMovement::query()->count())->toBe(0);
    expect(FinanceTransfer::query()->count())->toBe(0);
});

test('with sales enabled the POS sale is the same commercial sale, visible in Sales without duplicates', function () {
    $company = Company::factory()->create();
    $operator = actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession], ['sales']);
    $session = openPosSession($company);
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'Livro', 'unit_price' => '45.00', 'quantity' => '1']], [['method' => 'cash', 'amount' => '45.00']]))->assertCreated();
    $order = SaleOrder::query()->sole();

    $this->get('/sales/orders')->assertForbidden();

    actingAsSalesMember($company, [SalePermission::View]);
    $this->get('/sales/orders?origin=pos')->assertOk()->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.id', $order->id)->where('orders.data.0.origin.value', 'pos'));
    $this->get("/sales/orders/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page->where('order.origin_reference', $order->origin_reference)->where('order.user_name', $operator->name));
    expect(SaleOrder::query()->count())->toBe(1);
});

test('the module is registered as independent with optional integrations', function () {
    $this->seed(PlatformModuleSeeder::class);

    $pos = PlatformModule::query()->where('identifier', 'pos')->sole();
    expect($pos)->development_status->toBe('implemented')->dependencies->toBe([])->commercially_available->toBeFalse()
        ->optional_integrations->toBe(['customers', 'products', 'inventory', 'sales', 'finance']);
    expect(array_column($pos->permissions, 'key'))->toHaveCount(15);
    expect(CompanyModule::query()->count())->toBe(0);
});
