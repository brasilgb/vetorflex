<?php

use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Services\PosModule;
use App\Modules\Tenancy\Models\Company;

beforeEach(fn () => $this->withoutVite());

/** Empresa somente PDV com uma sessão fechada e outra aberta, cada uma com uma venda em dinheiro. */
function posPagesScenario(): array
{
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $closed = openPosSession($company, '50.00', 'CX01');
    $sell = fn (PosSession $session): PosSale => PosSale::query()->findOrFail(test()->postJson("/pos/sessions/{$session->id}/sales", posCart(
        [['description' => 'Café', 'unit_price' => '8.00', 'quantity' => '2']],
        [['method' => 'cash', 'amount' => '20.00']],
    ))->assertCreated()->json('sale.id'));
    $sell($closed);
    test()->post("/pos/sessions/{$closed->id}/close", ['counted_cash' => '60.00', 'closing_notes' => 'Faltou troco'])->assertSessionHasNoErrors();
    $open = openPosSession($company, '30.00', 'CX02');
    $sale = $sell($open);

    return [$company, $closed->refresh(), $open, $sale];
}

test('every pos page renders with real data', function () {
    [, $closed, $open, $sale] = posPagesScenario();

    $this->get('/pos')->assertOk()->assertInertia(fn ($page) => $page->component('pos/index')->has('mySessions', 1));
    $this->get('/pos/terminals')->assertOk()->assertInertia(fn ($page) => $page->component('pos/terminals')->has('terminals', 2));
    $this->get('/pos/sessions')->assertOk()->assertInertia(fn ($page) => $page->component('pos/sessions/index')->has('sessions.data', 2));
    $this->get("/pos/sessions/{$closed->id}")->assertOk()->assertInertia(fn ($page) => $page->component('pos/sessions/show')
        ->where('session.divergence', '-6.00')->where('summary.sales_total', '16.00')->where('canClose', false)->where('canCorrect', true));
    $this->get("/pos/sessions/{$open->id}")->assertOk()->assertInertia(fn ($page) => $page->component('pos/sessions/show')->where('canClose', true));
    $this->get('/pos/sales')->assertOk()->assertInertia(fn ($page) => $page->component('pos/sales/index')->has('sales.data', 2));
    $this->get("/pos/sales/{$sale->id}")->assertOk()->assertInertia(fn ($page) => $page->component('pos/sales/show')
        ->has('items', 1)->where('items.0.returnable', '2.0000')->has('payments', 1)->where('canReturn', true)->where('salesEnabled', false));
    $this->get('/pos/settings')->assertOk()->assertInertia(fn ($page) => $page->component('pos/settings')->where('financeEnabled', false)->where('accounts', []));
    $this->get('/pos/reports')->assertOk()->assertInertia(fn ($page) => $page->component('pos/reports')->where('summary.sales_count', 2)->where('summary.total', '32.00'));
});

test('every pos report builds and exports to CSV', function (string $type) {
    posPagesScenario();

    $this->get("/pos/reports?type={$type}")->assertOk()->assertInertia(fn ($page) => $page->component('pos/reports')->where('report.type', $type));
    expect($this->get("/pos/reports/csv?type={$type}")->assertOk()->streamedContent())->toStartWith("\u{FEFF}");
})->with(['by_operator', 'by_terminal', 'by_period', 'payment_methods', 'products', 'cancellations', 'returns', 'divergences', 'sessions']);

test('the divergence report lists only sessions closed with a difference', function () {
    posPagesScenario();

    $this->get('/pos/reports?type=divergences')->assertInertia(fn ($page) => $page->has('report.rows', 1)->where('report.rows.0.divergence', '-6.00')->where('report.rows.0.notes', 'Faltou troco'));
});

test('the first receipt is free while the drawer is open and a second copy needs permission and is counted', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession]);
    $session = openPosSession($company);
    $saleId = $this->postJson("/pos/sessions/{$session->id}/sales", posCart(
        [['description' => 'Pão', 'unit_price' => '5.00', 'quantity' => '1']],
        [['method' => 'pix', 'amount' => '5.00']],
    ))->assertCreated()->json('sale.id');

    $this->get("/pos/sales/{$saleId}/receipt")->assertOk()->assertInertia(fn ($page) => $page->component('pos/receipt')->where('receipt.is_copy', false));
    $this->get("/pos/sales/{$saleId}/receipt?copy=1")->assertForbidden();

    actingAsPosMember($company, [PosPermission::Operate, PosPermission::Reprint]);
    $this->get("/pos/sales/{$saleId}/receipt?copy=1")->assertOk()->assertInertia(fn ($page) => $page->where('receipt.is_copy', true));
    expect($this->get("/pos/sales/{$saleId}/receipt.pdf?copy=1")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent())->toStartWith('%PDF');
    expect(PosSale::query()->findOrFail($saleId)->reprints)->toBe(2);
});

test('settings and favorites are saved for the company', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);

    $this->put('/pos/settings', [
        'allow_anonymous' => false,
        'max_discount_percent' => '5.5',
        'discount_requires_authorization' => true,
        'allow_price_change' => true,
        'payment_methods' => ['cash', 'pix'],
        'blind_close' => true,
        'print_receipt' => false,
        'receipt_header' => 'Padaria Exemplo',
        'receipt_footer' => null,
        'finance_accounts' => [],
        'cash_account_id' => null,
        'treasury_account_id' => null,
    ])->assertSessionHasNoErrors();

    $settings = app(PosModule::class)->settings($company->refresh());
    expect($settings)->allowsAnonymousCustomer->toBeFalse()->blindClose->toBeTrue()->paymentMethods->toBe(['cash', 'pix'])->receiptHeader->toBe('Padaria Exemplo');

    $this->put('/pos/settings', ['payment_methods' => []])->assertSessionHasErrors('payment_methods');
    $this->put('/pos/favorites', ['favorites' => [999999]])->assertSessionHasErrors('favorites');
});

test('pos pages require the matching individual permission', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, [PosPermission::Operate]);

    foreach (['/pos/terminals', '/pos/sessions', '/pos/sales', '/pos/settings', '/pos/reports'] as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->get('/pos')->assertOk();
});
