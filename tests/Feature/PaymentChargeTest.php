<?php

use App\Models\User;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\Parties\Models\Party;
use App\Modules\Payments\Enums\AccountStatus;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Enums\PaymentPermission;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentProviderAvailability;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

function chargeData(array $overrides = []): array
{
    return ['description' => 'Mensalidade de novembro', 'amount' => '10.00', 'due_date' => now()->addDays(3)->format('Y-m-d'), 'payer' => ['name' => 'Maria Pagadora', 'document' => '529.982.247-25'], 'idempotency_key' => (string) Str::uuid(), ...$overrides];
}

function paidPix(string $e2e = 'E00000000202611271200abcdef12345', string $amount = '10.00'): array
{
    return ['endToEndId' => $e2e, 'valor' => $amount, 'horario' => now()->toIso8601String(), 'pagador' => ['nome' => 'Maria Pagadora', 'cpf' => '52998224725']];
}

test('a company with only payments issues a pix charge through its own provider account', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    efiAccount($company);

    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();

    $charge = PaymentCharge::query()->sole();
    expect($charge)->status->toBe(ChargeStatus::Pending)->amount->toBe('10.00')->origin->value->toBe('manual')
        ->and($charge->provider_charge_id)->toMatch('/^[A-Z0-9]{32}$/')
        ->and($charge->pix_copy_paste)->not->toBeNull()
        ->and($charge->qr_code_image)->toStartWith('data:image/');
    expect(Party::query()->where('company_id', $company->id)->sole())->name->toBe('Maria Pagadora')->document->toBe('52998224725');
    expect($state['calls'])->toContain('POST /oauth/token', "PUT /v2/cob/{$charge->provider_charge_id}", 'GET /v2/loc/77/qrcode');
    $this->get('/sales')->assertForbidden();
    $this->get('/finance')->assertForbidden();
});

test('repeating the creation request returns the same charge and a reused key with other data is refused', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    efiAccount($company);
    $data = chargeData();

    $this->post('/payments/charges', $data)->assertSessionHasNoErrors();
    $this->post('/payments/charges', $data)->assertSessionHasNoErrors();
    $this->post('/payments/charges', [...$data, 'amount' => '11.00'])->assertSessionHasErrors('idempotency_key');

    expect(PaymentCharge::query()->count())->toBe(1)
        ->and(collect($state['calls'])->filter(fn (string $call): bool => str_starts_with($call, 'PUT /v2/cob/'))->count())->toBe(1);
});

test('without an active provider account the charge stays as a draft and can be issued later', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);

    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();
    expect($charge->status)->toBe(ChargeStatus::Draft);

    efiAccount($company);
    $this->post("/payments/charges/{$charge->id}/issue")->assertSessionHasNoErrors();
    expect($charge->refresh()->status)->toBe(ChargeStatus::Pending);
});

test('a confirmed webhook marks the charge as paid once, even when repeated or out of order', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();

    $state['status'] = 'CONCLUIDA';
    $state['pix'] = [paidPix()];
    $payload = ['pix' => [['endToEndId' => 'E00000000202611271200abcdef12345', 'txid' => $charge->provider_charge_id, 'valor' => '10.00', 'horario' => now()->toIso8601String()]]];

    efiWebhook($account, $payload)->assertOk();
    efiWebhook($account, $payload)->assertOk();
    efiWebhook($account, ['pix' => [[...$payload['pix'][0], 'infoPagador' => 'reenvio fora de ordem']]])->assertOk();

    expect($charge->refresh())->status->toBe(ChargeStatus::Paid)->paid_amount->toBe('10.00')
        ->and(PaymentTransaction::query()->count())->toBe(1)
        ->and(PaymentWebhookEvent::query()->count())->toBe(2)
        ->and(PaymentWebhookEvent::query()->where('status', WebhookStatus::Processed)->count())->toBe(2)
        ->and($charge->events()->where('event', 'paid')->count())->toBe(1);

    // O webhook só indica o que consultar: sem pagamento na consulta, nada muda.
    $other = PaymentCharge::query()->whereKeyNot($charge->id)->count();
    expect($other)->toBe(0);
});

test('the webhook never trusts the payload alone and ignores charges of other accounts', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();

    efiWebhook($account, ['pix' => [['endToEndId' => 'E-falso', 'txid' => $charge->provider_charge_id, 'valor' => '10.00']]])->assertOk();
    expect($charge->refresh()->status)->toBe(ChargeStatus::Pending)->and(PaymentTransaction::query()->count())->toBe(0);

    $otherCompany = Company::factory()->create();
    $otherAccount = efiAccount($otherCompany);
    efiWebhook($otherAccount, ['pix' => [['endToEndId' => 'E1', 'txid' => $charge->provider_charge_id, 'valor' => '10.00']]])->assertOk();
    expect(PaymentWebhookEvent::query()->where('payment_provider_account_id', $otherAccount->id)->sole()->status)->toBe(WebhookStatus::Ignored);

    $this->postJson('/webhooks/payments/efi/'.Str::random(48), ['pix' => []])->assertNotFound();
});

test('webhook origin is checked against the allowed IPs when enforcement is on', function () {
    $company = Company::factory()->create();
    $account = efiAccount($company);
    config(['payments.webhooks.enforce_ips' => true, 'payments.webhooks.allowed_ips.efi' => ['34.193.116.226']]);

    efiWebhook($account, ['pix' => []])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '34.193.116.226']);
    efiWebhook($account, ['pix' => []])->assertOk();
});

test('an open charge is cancelled at the provider and a paid charge cannot be cancelled', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();

    $this->post("/payments/charges/{$charge->id}/cancel", ['reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();
    expect($charge->refresh()->status)->toBe(ChargeStatus::Cancelled)->and($state['calls'])->toContain("PATCH /v2/cob/{$charge->provider_charge_id}");

    $state = ['status' => 'CONCLUIDA', 'pix' => [paidPix('E2')]];
    fakeEfi($state);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $paid = PaymentCharge::query()->latest('id')->first();
    $this->post("/payments/charges/{$paid->id}/sync")->assertSessionHasNoErrors();
    $this->post("/payments/charges/{$paid->id}/cancel", ['reason' => 'x'])->assertSessionHasErrors('charge');
});

test('a refund is requested at the provider and confirmed later, without duplicating', function () {
    $state = ['status' => 'CONCLUIDA', 'pix' => [paidPix()]];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();
    $this->post("/payments/charges/{$charge->id}/sync")->assertSessionHasNoErrors();
    $transaction = PaymentTransaction::query()->sole();
    $key = (string) Str::uuid();

    $this->post("/payments/charges/{$charge->id}/refunds", ['payment_transaction_id' => $transaction->id, 'amount' => '10.00', 'reason' => 'Produto devolvido', 'idempotency_key' => $key])->assertSessionHasNoErrors();
    $this->post("/payments/charges/{$charge->id}/refunds", ['payment_transaction_id' => $transaction->id, 'amount' => '10.00', 'reason' => 'Produto devolvido', 'idempotency_key' => $key])->assertSessionHasNoErrors();
    $refund = PaymentRefund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Processing)->and($state['refund_requests'])->toHaveCount(1);

    $state['devolucoes'] = ['E00000000202611271200abcdef12345' => [['id' => $refund->provider_refund_id, 'rtrId' => 'D1', 'valor' => '10.00', 'status' => 'DEVOLVIDO']]];
    efiWebhook($account, ['pix' => [['endToEndId' => 'E00000000202611271200abcdef12345', 'txid' => $charge->provider_charge_id, 'devolucoes' => [['id' => $refund->provider_refund_id, 'status' => 'DEVOLVIDO']]]]])->assertOk();

    expect($refund->refresh()->status)->toBe(RefundStatus::Refunded)
        ->and($charge->refresh())->status->toBe(ChargeStatus::Refunded)->refunded_amount->toBe('10.00');
    $this->post("/payments/charges/{$charge->id}/refunds", ['payment_transaction_id' => $transaction->id, 'amount' => '1.00', 'reason' => 'De novo', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('amount');
});

test('credentials are encrypted at rest and never sent to the screen', function () {
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);

    $raw = DB::table('payment_provider_accounts')->where('id', $account->id)->value('credentials');
    expect($raw)->not->toContain('Client_Secret_muito_secreto')->not->toContain('Client_Id_teste');

    $this->get('/payments/settings')->assertOk()->assertInertia(fn ($page) => $page->component('payments/settings')
        ->where('accounts.0.has_client_secret', true)->where('accounts.0.has_certificate', true)->missing('accounts.0.credentials')->missing('accounts.0.client_secret'));
    expect(json_encode($this->get('/payments/settings')->viewData('page')))->not->toContain('Client_Secret_muito_secreto');
});

test('saving settings keeps secrets left blank, requires a new connection test and only allows available providers', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);
    $base = ['provider' => 'efi', 'environment' => 'homologation', 'pix_key' => 'nova-chave@example.com', 'expiration_seconds' => 1800, 'methods' => ['pix', 'boleto']];

    $this->post('/payments/settings', $base)->assertSessionHasNoErrors();
    expect($account->refresh())->status->value->toBe('untested')->methods->toBe(['pix'])
        ->and($account->credentials['client_secret'])->toBe('Client_Secret_muito_secreto');

    $this->post("/payments/settings/accounts/{$account->id}/test")->assertSessionHasNoErrors();
    expect($account->refresh()->status->value)->toBe('active');

    PaymentProviderAvailability::query()->where('identifier', 'efi')->update(['is_available' => false]);
    $this->post('/payments/settings', $base)->assertSessionHasErrors('provider');
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    expect(PaymentCharge::query()->sole()->status)->toBe(ChargeStatus::Draft);
});

test('permissions are individual and charges of another company are not reachable', function () {
    $state = [];
    fakeEfi($state);
    $other = Company::factory()->create();
    actingAsPaymentsMember($other);
    efiAccount($other);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $foreign = PaymentCharge::query()->sole();

    $company = Company::factory()->create();
    actingAsPaymentsMember($company, [PaymentPermission::View]);

    $this->get('/payments/charges')->assertOk();
    $this->get("/payments/charges/{$foreign->id}")->assertNotFound();
    $this->post('/payments/charges', chargeData())->assertForbidden();
    $this->get('/payments/settings')->assertForbidden();
    $this->get('/payments/reconciliation')->assertForbidden();
    $this->get('/payments/charges/export')->assertForbidden();
    $this->post("/payments/charges/{$foreign->id}/cancel", ['reason' => 'x'])->assertNotFound();
});

test('deactivating the module keeps charges and webhooks keep confirming real payments', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();

    CompanyModule::query()->where('company_id', $company->id)->update(['deactivated_at' => now()]);
    $this->get('/payments')->assertForbidden();
    $state['status'] = 'CONCLUIDA';
    $state['pix'] = [paidPix()];
    efiWebhook($account, ['pix' => [['endToEndId' => 'E00000000202611271200abcdef12345', 'txid' => $charge->provider_charge_id]]])->assertOk();

    CompanyModule::query()->where('company_id', $company->id)->update(['deactivated_at' => null]);
    $this->get("/payments/charges/{$charge->id}")->assertOk()->assertInertia(fn ($page) => $page->component('payments/charges/show')->where('charge.status', 'paid'));
});

test('the reconciliation command syncs open charges and marks overdue ones', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    efiAccount($company);
    $this->post('/payments/charges', chargeData(['due_date' => now()->addDay()->format('Y-m-d')]))->assertSessionHasNoErrors();
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    [$late, $paid] = PaymentCharge::query()->orderBy('id')->get()->all();
    $late->update(['due_date' => now()->subDays(2)->format('Y-m-d')]);
    $state['status'] = 'ATIVA';

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($late->refresh()->status)->toBe(ChargeStatus::Overdue)->and($paid->refresh()->status)->toBe(ChargeStatus::Pending);
});

test('failed webhook events can be reprocessed with permission', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $charge = PaymentCharge::query()->sole();
    $event = PaymentWebhookEvent::create(['company_id' => $company->id, 'payment_provider_account_id' => $account->id, 'provider' => 'efi', 'payload_hash' => str_repeat('a', 64), 'payload' => ['pix' => [['txid' => $charge->provider_charge_id]]], 'status' => WebhookStatus::Failed, 'error' => 'Falha simulada']);
    $state['status'] = 'CONCLUIDA';
    $state['pix'] = [paidPix()];

    $this->post("/payments/webhook-events/{$event->id}/reprocess")->assertSessionHasNoErrors();

    expect($event->refresh()->status)->toBe(WebhookStatus::Processed)->and($charge->refresh()->status)->toBe(ChargeStatus::Paid);
});

test('the rootadmin controls provider availability and sees operational data without amounts', function () {
    $company = Company::factory()->create();
    efiAccount($company)->update(['status' => AccountStatus::Failing, 'last_error' => 'A Efí recusou as credenciais.']);
    $root = User::factory()->create(['is_root_admin' => true]);
    $provider = PaymentProviderAvailability::query()->where('identifier', 'efi')->sole();

    $this->actingAs($root)->get('/rootadmin/payments')->assertOk()->assertInertia(fn ($page) => $page->component('root-admin/payments')
        ->where('indicators.failing_accounts', 1)->where('failing.0.last_error', 'A Efí recusou as credenciais.')->missing('failing.0.credentials'));
    $this->actingAs($root)->patch("/rootadmin/payments/providers/{$provider->id}", ['is_available' => false])->assertSessionHasNoErrors();
    expect($provider->refresh()->is_available)->toBeFalse();

    actingAsPaymentsMember($company);
    $this->get('/rootadmin/payments')->assertForbidden();
});

test('every payments page renders', function (string $url, string $component) {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/payments', 'payments/dashboard'],
    ['/payments/charges', 'payments/charges/index'],
    ['/payments/charges/create', 'payments/charges/create'],
    ['/payments/reconciliation', 'payments/reconciliation'],
    ['/payments/reports', 'payments/reports'],
    ['/payments/settings', 'payments/settings'],
]);

test('charges and receipts export to CSV', function () {
    $state = ['status' => 'CONCLUIDA', 'pix' => [paidPix()]];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    efiAccount($company);
    $this->post('/payments/charges', chargeData())->assertSessionHasNoErrors();
    $this->post('/payments/charges/'.PaymentCharge::query()->sole()->id.'/sync')->assertSessionHasNoErrors();

    expect($this->get('/payments/charges/export')->assertOk()->streamedContent())->toStartWith("\u{FEFF}")->toContain('Mensalidade de novembro');
    expect($this->get('/payments/reports/csv')->assertOk()->streamedContent())->toContain('E00000000202611271200abcdef12345');
});

test('registering the efí webhook skips server mtls, relying on the secret url and the provider query', function () {
    $state = [];
    fakeEfi($state);
    $company = Company::factory()->create();
    actingAsPaymentsMember($company);
    $account = efiAccount($company);

    $this->post("/payments/settings/accounts/{$account->id}/webhook")->assertSessionHasNoErrors();

    expect($state['webhook_headers'])->toBe(['true']);
});
