<?php

use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleConfirmed;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

/** Venda pelo HTTP; confirmada quando pedido. */
function financeSale(Company $company, string $quantity, array $overrides = [], bool $confirm = true): SaleOrder
{
    $variant = sellableVariant($company, '50.00');
    test()->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => $quantity]], $overrides))->assertSessionHasNoErrors();
    $order = SaleOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();

    if ($confirm) {
        test()->post("/sales/orders/{$order->id}/confirm")->assertSessionHasNoErrors();
    }

    return $order->refresh();
}

/** Pedido de compra confirmado pelo HTTP. */
function financePurchase(Company $company, string $quantity, string $unitCost, array $overrides = []): PurchaseOrder
{
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $variant = purchasableVariant($company);
    test()->post('/purchases/orders', purchaseOrderData($supplier, [['product_variant_id' => $variant->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]], ['idempotency_key' => (string) Str::uuid(), ...$overrides]))->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    test()->post("/purchases/orders/{$order->id}/confirm")->assertSessionHasNoErrors();

    return $order->refresh();
}

function saleTitles(SaleOrder $order)
{
    return FinanceTitle::query()->where('source_type', 'sale_order')->where('source_id', $order->id)->orderBy('due_date')->orderBy('id')->get();
}

test('draft sales do not generate receivables and confirmation generates the installments once', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company, alsoEnable: ['sales']);

    $draft = financeSale($company, '2', confirm: false);
    expect(FinanceTitle::query()->count())->toBe(0);

    $order = financeSale($company, '2', [
        'payment_condition' => 'installments',
        'installments' => [['due_date' => now()->addMonth()->format('Y-m-d'), 'amount' => '60'], ['due_date' => now()->addMonths(2)->format('Y-m-d'), 'amount' => '40']],
    ]);

    $titles = saleTitles($order);
    expect($titles)->toHaveCount(2);
    expect($titles->pluck('amount')->all())->toBe(['60.00', '40.00']);
    expect($titles->pluck('origin')->unique()->all())->toBe([TitleOrigin::Sale]);
    expect($titles->pluck('status')->unique()->all())->toBe([TitleStatus::Pending]);
    expect($titles->pluck('settled_principal')->unique()->all())->toBe(['0.00']);
    expect(FinanceTitle::query()->where('source_id', $draft->id)->where('source_type', 'sale_order')->count())->toBe(0);

    event(new SaleConfirmed($order->fresh()));
    expect(saleTitles($order))->toHaveCount(2);
});

test('cancelling a sale cancels the open receivables but keeps what was received and creates a refund', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company, alsoEnable: ['sales']);
    $account = financeAccount($company);
    $order = financeSale($company, '2');
    $title = saleTitles($order)->sole();
    expect($title->amount)->toBe('100.00');

    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '30.00'))->assertSessionHasNoErrors();
    $this->post("/sales/orders/{$order->id}/cancel", ['reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();

    $title->refresh();
    expect($title->settled_principal)->toBe('30.00');
    expect($title->openBalance()->isZero())->toBeTrue();
    expect(FinanceSettlement::query()->sole()->status->value)->toBe('confirmed');
    $refund = FinanceTitle::query()->where('origin', TitleOrigin::SaleRefund)->sole();
    expect($refund)->kind->value->toBe('payable')->amount->toBe('30.00')->status->toBe(TitleStatus::Pending);

    event(new SaleCancelled($order->fresh(), 'repetido'));
    expect(FinanceTitle::query()->where('origin', TitleOrigin::SaleRefund)->count())->toBe(1);
});

test('sales keep working when finance is not enabled', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);

    $order = financeSale($company, '1');
    $this->post("/sales/orders/{$order->id}/cancel", ['reason' => 'Teste'])->assertSessionHasNoErrors();

    expect(FinanceTitle::query()->count())->toBe(0);
});

test('a confirmed purchase becomes a payable and receiving goods is not a payment', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company, alsoEnable: ['products', 'purchases']);
    $order = financePurchase($company, '4', '25.00');

    $title = FinanceTitle::query()->where('origin', TitleOrigin::Purchase)->sole();
    expect($title)->kind->value->toBe('payable')->amount->toBe('100.00');

    $item = $order->items()->sole();
    $this->post("/purchases/orders/{$order->id}/receipts", [
        'received_at' => now()->format('Y-m-d'), 'document_reference' => 'NF 1', 'idempotency_key' => (string) Str::uuid(),
        'items' => [['purchase_order_item_id' => $item->id, 'quantity' => '4']],
    ])->assertSessionHasNoErrors();

    expect($title->refresh())->status->toBe(TitleStatus::Pending)->settled_principal->toBe('0.00');
    expect(FinanceSettlement::query()->count())->toBe(0);
});

test('cancelling a purchase cancels the open payable', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company, alsoEnable: ['products', 'purchases']);
    $order = financePurchase($company, '2', '10.00');

    $this->post("/purchases/orders/{$order->id}/cancel", ['reason' => 'Fornecedor sem estoque'])->assertSessionHasNoErrors();

    $title = FinanceTitle::query()->where('origin', TitleOrigin::Purchase)->sole();
    expect($title->openBalance()->isZero())->toBeTrue();
    expect(FinanceTitle::query()->where('origin', TitleOrigin::PurchaseRefund)->count())->toBe(0);
});

test('assisted import previews eligible sales, is idempotent and marks external settlements without moving accounts', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $first = financeSale($company, '1');
    $second = financeSale($company, '2');
    $draft = financeSale($company, '1', confirm: false);
    expect(FinanceTitle::query()->count())->toBe(0);

    actingAsFinanceMember($company, alsoEnable: ['sales']);
    $range = ['type' => 'sales', 'from' => now()->subDay()->format('Y-m-d'), 'until' => now()->addDay()->format('Y-m-d')];

    $this->get('/finance/import?'.http_build_query($range))->assertOk()
        ->assertInertia(fn ($page) => $page->component('finance/import')->has('preview', 2)->where('preview.0.is_eligible', true));

    $this->post('/finance/import', [...$range, 'selected' => [$first->id, $draft->id]])->assertSessionHasErrors();
    expect(FinanceTitle::query()->count())->toBe(0);
    $this->post('/finance/import', [...$range, 'selected' => [$first->id, $second->id], 'settled_externally' => [$first->id]])->assertSessionHasNoErrors();
    $this->post('/finance/import', [...$range, 'selected' => [$first->id, $second->id]])->assertSessionHasNoErrors();

    expect(FinanceTitle::query()->count())->toBe(2);
    expect(saleTitles($first)->sole()->status)->toBe(TitleStatus::Settled);
    expect(FinanceSettlement::query()->sole())->is_external->toBeTrue()->finance_account_id->toBeNull();
    expect(saleTitles($second)->sole()->status)->toBe(TitleStatus::Pending);
    expect(FinanceMovement::query()->count())->toBe(0);
});

test('disabling finance keeps its data and does not stop sales', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company, alsoEnable: ['sales']);
    financeSale($company, '1');
    expect(FinanceTitle::query()->count())->toBe(1);

    CompanyModule::query()->where('company_id', $company->id)
        ->whereHas('module', fn ($query) => $query->where('identifier', 'finance'))->delete();

    $this->get('/finance')->assertForbidden();
    financeSale($company, '1');
    expect(FinanceTitle::query()->count())->toBe(1);
});
