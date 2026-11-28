<?php

use App\Modules\Finance\Enums\SettlementStatus;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

function paymentSale(Company $company, string $price = '30.00'): SaleOrder
{
    $variant = sellableVariant($company, $price);
    test()->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']], ['payment_condition' => 'cash', 'payment_method' => 'pix']))->assertSessionHasNoErrors();
    $order = SaleOrder::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    test()->post("/sales/orders/{$order->id}/confirm")->assertSessionHasNoErrors();

    return $order->refresh();
}

function confirmPix(array &$state, $account, PaymentCharge $charge, string $e2e, string $amount): void
{
    $state['status'] = 'CONCLUIDA';
    $state['pix'] = [...($state['pix'] ?? []), ['endToEndId' => $e2e, 'valor' => $amount, 'horario' => now()->toIso8601String()]];
    efiWebhook($account, ['pix' => [['endToEndId' => $e2e, 'txid' => $charge->provider_charge_id, 'valor' => $amount]]])->assertOk();
}

test('a charge from a sale bills the balance due, refuses a second open charge and works without finance', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company, null, ['sales']);
    $account = efiAccount($company);
    $order = paymentSale($company);

    $this->get("/payments/charges/create?origin=sale&origin_id={$order->id}")->assertOk()->assertInertia(fn ($page) => $page->component('payments/charges/create')->where('prefill.amount', '30.00'));
    $this->post('/payments/charges', ['origin' => 'sale', 'origin_id' => $order->id, 'due_date' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $this->post('/payments/charges', ['origin' => 'sale', 'origin_id' => $order->id, 'due_date' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('origin_id');

    $charge = PaymentCharge::query()->sole();
    expect($charge)->amount->toBe('30.00')->origin_type->toBe('sale_order')->origin_label->toBe("Venda #{$order->number}");

    confirmPix($state, $account, $charge, 'E-sale-1', '30.00');
    expect($charge->refresh()->status)->toBe(ChargeStatus::Paid);
    $this->post('/payments/charges', ['origin' => 'sale', 'origin_id' => $order->id, 'due_date' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('amount');
});

test('sale charges need the sales module and only accept sales of the same company', function () {
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);

    $this->post('/payments/charges', ['origin' => 'sale', 'origin_id' => 1, 'due_date' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('origin');
    $this->post('/payments/charges', ['origin' => 'finance', 'origin_id' => 1, 'due_date' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('origin');
});

test('with finance, a confirmed payment settles the receivable once and a refund reverses it', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    $user = actingAsPaymentsMember($company, null, ['finance']);
    $bank = financeAccount($company, '0.00', 'Conta Efí');
    $account = efiAccount($company, $bank->id);
    $this->post('/finance/titles', financeTitleData('receivable', ['amount' => '80.00']))->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();

    $this->post('/payments/charges', ['origin' => 'finance', 'origin_id' => $title->id, 'due_date' => $title->due_date->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();
    expect($charge->amount)->toBe('80.00');

    confirmPix($state, $account, $charge, 'E-title-1', '80.00');
    efiWebhook($account, ['pix' => [['endToEndId' => 'E-title-1', 'txid' => $charge->provider_charge_id, 'reenvio' => true]]])->assertOk();

    expect($title->refresh()->status->value)->toBe('settled')
        ->and(FinanceSettlement::query()->where('status', SettlementStatus::Confirmed)->count())->toBe(1)
        ->and(FinanceMovement::query()->where('finance_account_id', $bank->id)->count())->toBe(1);

    $transaction = PaymentTransaction::query()->sole();
    $this->post("/payments/charges/{$charge->id}/refunds", ['payment_transaction_id' => $transaction->id, 'amount' => '30.00', 'reason' => 'Desconto concedido depois', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $refund = PaymentRefund::query()->sole();
    $state['devolucoes'] = ['E-title-1' => [['id' => $refund->provider_refund_id, 'valor' => '30.00', 'status' => 'DEVOLVIDO']]];
    $this->post("/payments/charges/{$charge->id}/sync")->assertSessionHasNoErrors();

    expect(FinanceSettlement::query()->where('status', SettlementStatus::Reversed)->count())->toBe(1)
        ->and(FinanceSettlement::query()->where('status', SettlementStatus::Confirmed)->sole()->principal)->toBe('50.00')
        ->and($title->refresh()->openBalance()->__toString())->toBe('30.00')
        ->and($charge->refresh()->status)->toBe(ChargeStatus::Paid);
});

test('without a finance account configured the payment is kept in payments only', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company, null, ['finance']);
    $account = efiAccount($company);
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();
    $this->post('/payments/charges', ['origin' => 'finance', 'origin_id' => $title->id, 'due_date' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

    confirmPix($state, $account, PaymentCharge::query()->sole(), 'E-x', '100.00');

    expect(FinanceSettlement::query()->count())->toBe(0)->and(PaymentCharge::query()->sole()->status)->toBe(ChargeStatus::Paid);
});

test('a sale paid by pix with sales and finance settles the sale receivable once, without a second revenue', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company, null, ['sales', 'finance']);
    $bank = financeAccount($company, '0.00', 'Conta Efí');
    $account = efiAccount($company, $bank->id);
    $order = paymentSale($company, '45.00');

    $this->post('/payments/charges', ['origin' => 'sale', 'origin_id' => $order->id, 'due_date' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    confirmPix($state, $account, PaymentCharge::query()->sole(), 'E-sale-fin', '45.00');

    $titles = FinanceTitle::query()->where('source_type', 'sale_order')->where('source_id', $order->id)->get();
    expect($titles)->toHaveCount(1)
        ->and($titles->first()->status->value)->toBe('settled')
        ->and(FinanceSettlement::query()->count())->toBe(1)
        ->and(FinanceTitle::query()->count())->toBe(1);
});

test('pos pix is confirmed only by the provider, used once and not settled twice', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    $user = actingAsPosMember($company, null, ['finance']);
    actingAsPaymentsMember($company, null, ['pos'], user: $user);
    $bank = financeAccount($company, '0.00', 'Conta Efí');
    $account = efiAccount($company, $bank->id);
    $session = openPosSession($company);
    $cartKey = (string) Str::uuid();
    $cart = fn (int $chargeId) => posCart([['description' => 'Bolo', 'unit_price' => '12.00', 'quantity' => '1']], [['method' => 'pix', 'amount' => '12.00', 'payment_charge_id' => $chargeId]]);

    $this->get("/pos/sessions/{$session->id}/register")->assertInertia(fn ($page) => $page->where('providerPix', true));
    $created = $this->postJson("/pos/sessions/{$session->id}/pix", ['amount' => '12.00', 'cart_key' => $cartKey, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json();
    expect($created)->status->toBe('pending')->is_confirmed->toBeFalse()->qr_code_image->toStartWith('data:image/');

    $this->postJson("/pos/sessions/{$session->id}/sales", $cart($created['id']))->assertUnprocessable()->assertJsonValidationErrors('payments.0.payment_charge_id');

    $state['status'] = 'CONCLUIDA';
    $state['pix'] = [['endToEndId' => 'E-pos-1', 'valor' => '12.00', 'horario' => now()->toIso8601String()]];
    $this->travel(5)->seconds();
    $this->getJson("/pos/sessions/{$session->id}/pix/{$created['id']}")->assertOk()->assertJson(['is_confirmed' => true]);

    $this->postJson("/pos/sessions/{$session->id}/sales", $cart($created['id']))->assertCreated();
    $this->postJson("/pos/sessions/{$session->id}/sales", $cart($created['id']))->assertUnprocessable();

    $payment = PosPayment::query()->sole();
    expect($payment)->is_provider_confirmed->toBeTrue()->payment_charge_id->toBe($created['id'])
        ->and(PaymentCharge::query()->sole()->origin_label)->toStartWith('PDV — venda #')
        ->and(FinanceSettlement::query()->count())->toBeLessThanOrEqual(1);
});

test('the pos keeps manual payments when payments is not contracted', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company);

    $this->get("/pos/sessions/{$session->id}/register")->assertInertia(fn ($page) => $page->where('providerPix', false));
    $this->postJson("/pos/sessions/{$session->id}/pix", ['amount' => '5.00', 'cart_key' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'Café', 'unit_price' => '5.00', 'quantity' => '1']], [['method' => 'pix', 'amount' => '5.00']]))->assertCreated();
    expect(PosPayment::query()->sole()->is_provider_confirmed)->toBeFalse();
});
