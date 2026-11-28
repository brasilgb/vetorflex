<?php

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

test('a company with only the POS sells custom items without customers, products, sales or finance', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company, '50.00');

    $response = $this->postJson("/pos/sessions/{$session->id}/sales", posCart(
        [['description' => 'Bolo de cenoura', 'unit_price' => '12.50', 'quantity' => '2']],
        [['method' => 'cash', 'amount' => '30.00']],
    ))->assertCreated();

    $order = SaleOrder::query()->sole();
    expect($order)->status->toBe(SaleOrderStatus::Completed)->origin->value->toBe('pos')->total->toBe('25.00')->customer_party_id->toBeNull();
    expect($order->origin_reference)->toContain('CX01')->toContain('sessão');
    expect($response->json('sale.change_total'))->toBe('5.00');
    expect(PosPayment::query()->sole())->amount->toBe('25.00')->tendered->toBe('30.00')->change->toBe('5.00')->is_received->toBeTrue();
    expect(InventoryMovement::query()->count())->toBe(0);
    $this->get('/sales')->assertForbidden();
    $this->get('/finance')->assertForbidden();
    $this->get('/products')->assertForbidden();
});

test('combined payments split the server total and keep the term part open', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company);
    $customer = saleCustomer($company, ['name' => 'Joana']);

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart(
        [['description' => 'Serviço', 'unit_price' => '100.00', 'quantity' => '1']],
        [['method' => 'pix', 'amount' => '40.00'], ['method' => 'other', 'amount' => '35.00', 'is_term' => true, 'due_date' => now()->addMonth()->format('Y-m-d')], ['method' => 'cash', 'amount' => '50.00']],
        ['customer_party_id' => $customer->id],
    ))->assertCreated();

    $sale = PosSale::query()->sole();
    expect($sale)->total->toBe('100.00')->received_total->toBe('65.00')->term_total->toBe('35.00')->change_total->toBe('25.00');
    $order = $sale->order;
    expect($order->payment_condition->value)->toBe('combined');
    expect($order->paymentSchedules()->orderBy('sequence')->get()->map(fn ($row) => [$row->method->value, $row->amount])->all())
        ->toBe([['pix', '40.00'], ['cash', '25.00'], ['other', '35.00']]);
    expect(PosPayment::query()->orderBy('sequence')->pluck('schedule_sequence', 'method')->all())->toBe(['pix' => 1, 'cash' => 2, 'other' => 3]);
});

test('payments must cover the total, change only exists in cash and term sales need a customer', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company);
    $items = [['description' => 'Item', 'unit_price' => '20.00', 'quantity' => '1']];

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($items, [['method' => 'pix', 'amount' => '15.00']]))->assertJsonValidationErrors('payments');
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($items, [['method' => 'pix', 'amount' => '25.00']]))->assertJsonValidationErrors('payments');
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($items, [['method' => 'bank_slip', 'amount' => '20.00', 'is_term' => true, 'due_date' => now()->addWeek()->format('Y-m-d')]]))->assertJsonValidationErrors('customer_party_id');
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($items, []))->assertJsonValidationErrors('payments');

    expect(SaleOrder::query()->count())->toBe(0);
    expect(PosSale::query()->count())->toBe(0);
});

test('finalizing twice with the same key registers a single sale', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company);
    $cart = posCart([['description' => 'Café', 'unit_price' => '5.00', 'quantity' => '1']], [['method' => 'cash', 'amount' => '5.00']]);

    $first = $this->postJson("/pos/sessions/{$session->id}/sales", $cart)->assertCreated()->json('sale.id');
    $second = $this->postJson("/pos/sessions/{$session->id}/sales", $cart)->assertCreated()->json('sale.id');

    expect($second)->toBe($first);
    expect(SaleOrder::query()->count())->toBe(1);
    expect(PosSale::query()->count())->toBe(1);
    expect(PosPayment::query()->count())->toBe(1);
});

test('server prices are used and discounts above the limit need a supervisor', function () {
    $company = Company::factory()->create(['settings' => ['pos' => ['max_discount_percent' => '10']]]);
    $variant = sellableVariant($company, '50.00');
    actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession, PosPermission::ApplyDiscounts]);
    $session = openPosSession($company);
    $item = fn (string $discount) => [['product_variant_id' => $variant->id, 'quantity' => '2', 'unit_price' => '1.00', 'discount' => $discount]];

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($item('5.00'), [['method' => 'cash', 'amount' => '95.00']]))->assertCreated();
    expect(SaleOrder::query()->sole()->total)->toBe('95.00');

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($item('30.00'), [['method' => 'cash', 'amount' => '70.00']]))->assertJsonValidationErrors('authorization');

    $supervisor = User::factory()->create(['password' => 'senha-segura']);
    $supervisor->companies()->attach($company, ['role' => 'operator', 'is_active' => true, 'permissions' => [PosPermission::Authorize->value]]);
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($item('30.00'), [['method' => 'cash', 'amount' => '70.00']], ['authorization' => ['email' => $supervisor->email, 'password' => 'errada']]))->assertJsonValidationErrors('authorization');
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($item('30.00'), [['method' => 'cash', 'amount' => '70.00']], ['authorization' => ['email' => $supervisor->email, 'password' => 'senha-segura']]))->assertCreated();

    expect(PosSale::query()->latest('id')->first()->authorized_by_name)->toBe($supervisor->name);
});

test('only one session can be open per terminal, and sessions belong to their operator', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company);
    $terminal = PosTerminal::query()->sole();

    $this->post("/pos/terminals/{$terminal->id}/open", ['opening_amount' => '0', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('pos_terminal_id');
    expect(PosSession::query()->count())->toBe(1);

    actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession]);
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'X', 'unit_price' => '1', 'quantity' => '1']], [['method' => 'cash', 'amount' => '1']]))->assertJsonValidationErrors('session');
    $other = openPosSession($company, '10.00', 'CX02');
    expect($other->pos_terminal_id)->not->toBe($terminal->id);
    expect(PosSession::query()->where('status', 'open')->count())->toBe(2);
});

test('terminals with operators only accept those operators', function () {
    $company = Company::factory()->create();
    $owner = actingAsPosMember($company);
    $operator = User::factory()->create();
    $operator->companies()->attach($company, ['role' => 'operator', 'is_active' => true, 'permissions' => [PosPermission::Operate->value, PosPermission::OpenSession->value]]);
    $this->post('/pos/terminals', ['name' => 'Balcão', 'code' => 'BALCAO', 'operators' => [$owner->id]])->assertSessionHasNoErrors();
    $terminal = PosTerminal::query()->sole();

    $this->actingAs($operator)->withSession(['active_company_id' => $company->id]);
    $this->post("/pos/terminals/{$terminal->id}/open", ['opening_amount' => '0'])->assertSessionHasErrors('pos_terminal_id');
});

test('with stock enabled the sale moves inventory and respects availability', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, alsoEnable: ['products']);
    $variant = sellableVariant($company, '10.00', stock: '3', averageCost: '4.00');
    $session = openPosSession($company);

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['product_variant_id' => $variant->id, 'quantity' => '5']], [['method' => 'cash', 'amount' => '50.00']]))->assertUnprocessable();
    expect(SaleOrder::query()->count())->toBe(0);

    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['product_variant_id' => $variant->id, 'quantity' => '2']], [['method' => 'debit_card', 'amount' => '20.00']]))->assertCreated();
    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->sole())->quantity->toBe('1.0000')->reserved_quantity->toBe('0.0000');
});

test('the barcode scan and item search use the shared catalog', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $variant = sellableVariant($company, '7.90');
    $variant->update(['barcode' => '7891234567895']);

    $this->getJson('/pos/catalog/scan?code=7891234567895')->assertOk()->assertJsonPath('id', $variant->id)->assertJsonPath('price', '7.90');
    $this->getJson('/pos/catalog/scan?code=000')->assertNotFound();
    $this->getJson('/pos/catalog/search?term='.urlencode($variant->product->name))->assertOk()->assertJsonPath('0.id', $variant->id);
});

test('pos records of another company are not found', function () {
    $other = Company::factory()->create();
    actingAsPosMember($other);
    $foreign = openPosSession($other);

    $company = Company::factory()->create();
    actingAsPosMember($company);

    $this->postJson("/pos/sessions/{$foreign->id}/sales", posCart([['description' => 'X', 'unit_price' => '1', 'quantity' => '1']], [['method' => 'cash', 'amount' => '1']]))->assertNotFound();
    $this->get("/pos/sessions/{$foreign->id}")->assertNotFound();
    $this->post("/pos/terminals/{$foreign->pos_terminal_id}/open", ['opening_amount' => '0'])->assertNotFound();
});

test('pos permissions are individual and do not grant administrative or financial access', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, [PosPermission::Operate], ['finance', 'sales']);

    $this->get('/pos/terminals')->assertForbidden();
    $this->get('/pos/settings')->assertForbidden();
    $this->get('/pos/reports')->assertForbidden();
    $this->get('/pos/sessions')->assertForbidden();
    $this->get('/finance')->assertForbidden();
    $this->get('/sales')->assertForbidden();
    $this->get('/company/members')->assertForbidden();
    $terminal = PosTerminal::query()->create(['company_id' => $company->id, 'name' => 'Caixa', 'code' => 'CX9']);
    $this->post("/pos/terminals/{$terminal->id}/open", ['opening_amount' => '0'])->assertForbidden();
});
