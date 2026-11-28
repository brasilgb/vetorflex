<?php

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Models\PosCashMovement;
use App\Modules\Pos\Models\PosRefund;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

function posSell(PosSession $session, string $amount, string $method = 'cash', ?string $tendered = null): PosSale
{
    $id = test()->postJson("/pos/sessions/{$session->id}/sales", posCart(
        [['description' => 'Produto', 'unit_price' => $amount, 'quantity' => '1']],
        [['method' => $method, 'amount' => $tendered ?? $amount]],
    ))->assertCreated()->json('sale.id');

    return PosSale::query()->findOrFail($id);
}

function cashMovement(PosSession $session, string $type, string $amount, array $overrides = [])
{
    return test()->postJson("/pos/sessions/{$session->id}/cash-movements", ['type' => $type, 'amount' => $amount, 'reason' => 'Teste', 'idempotency_key' => (string) Str::uuid(), ...$overrides]);
}

test('opening cash is not revenue and the expected cash follows sales, supplies, withdrawals and refunds', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company, '100.00');

    posSell($session, '30.00', 'cash', '50.00');
    posSell($session, '40.00', 'pix');
    cashMovement($session, 'supply', '20.00')->assertCreated();
    cashMovement($session, 'withdrawal', '60.00')->assertCreated();
    cashMovement($session, 'withdrawal', '999.00')->assertJsonValidationErrors('amount');

    $summary = $this->getJson("/pos/sessions/{$session->id}/summary")->assertOk()->json();
    expect($summary)->opening->toBe('100.00')->sales_total->toBe('70.00')->cash_received->toBe('30.00')->change_total->toBe('20.00')
        ->supplies->toBe('20.00')->withdrawals->toBe('60.00')->expected_cash->toBe('90.00');
    expect(collect($summary['by_method'])->pluck('received', 'method')->all())->toBe(['cash' => '30.00', 'pix' => '40.00']);
});

test('closing records counted cash, requires a justification for divergence and locks the session', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company);
    $session = openPosSession($company, '50.00');
    posSell($session, '10.00');

    $this->post("/pos/sessions/{$session->id}/close", ['counted_cash' => '55.00'])->assertSessionHasErrors('closing_notes');
    $this->post("/pos/sessions/{$session->id}/close", ['counted_cash' => '55.00', 'closing_notes' => 'Faltaram R$ 5'])->assertSessionHasNoErrors();

    expect($session->refresh())->status->value->toBe('closed')->expected_cash->toBe('60.00')->counted_cash->toBe('55.00')->divergence->toBe('-5.00')->open_terminal_id->toBeNull();
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'X', 'unit_price' => '1', 'quantity' => '1']], [['method' => 'cash', 'amount' => '1']]))->assertJsonValidationErrors('session');
    cashMovement($session, 'supply', '5.00')->assertJsonValidationErrors('type');
    $this->post("/pos/sessions/{$session->id}/close", ['counted_cash' => '55.00', 'closing_notes' => 'De novo'])->assertSessionHasErrors('session');

    cashMovement($session, 'adjustment', '5.00', ['reason' => 'Dinheiro encontrado no cofre'])->assertCreated();
    expect(PosCashMovement::query()->where('is_post_close', true)->sole()->amount)->toBe('5.00');
    expect($session->refresh()->divergence)->toBe('-5.00');
    expect(fn () => PosCashMovement::query()->first()->update(['amount' => '1.00']))->toThrow(LogicException::class);

    $reopened = openPosSession($company, '0.00');
    expect($reopened->id)->not->toBe($session->id);
});

test('blind closing hides the expected cash from the operator', function () {
    $company = Company::factory()->create(['settings' => ['pos' => ['blind_close' => true]]]);
    actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession, PosPermission::CloseSession]);
    $session = openPosSession($company, '20.00');

    expect($session->is_blind)->toBeTrue();
    expect($this->getJson("/pos/sessions/{$session->id}/summary")->json())->not->toHaveKey('expected_cash');
});

test('withdrawals without permission need a supervisor and adjustments always need authorization', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession]);
    $session = openPosSession($company, '100.00');

    cashMovement($session, 'withdrawal', '10.00')->assertJsonValidationErrors('authorization');
    cashMovement($session, 'adjustment', '-2.00')->assertJsonValidationErrors('authorization');

    $supervisor = User::factory()->create(['password' => 'supervisor123']);
    $supervisor->companies()->attach($company, ['role' => 'operator', 'is_active' => true, 'permissions' => [PosPermission::Authorize->value]]);
    cashMovement($session, 'withdrawal', '10.00', ['authorization' => ['email' => $supervisor->email, 'password' => 'supervisor123']])->assertCreated();

    expect(PosCashMovement::query()->sole())->authorized_by_name->toBe($supervisor->name)->type->value->toBe('withdrawal');
});

test('a suspended sale keeps the cart without commercial records and can be resumed and completed', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, alsoEnable: ['products']);
    $variant = sellableVariant($company, '15.00', stock: '5');
    $session = openPosSession($company);

    $suspended = $this->postJson("/pos/sessions/{$session->id}/suspend", ['items' => [['product_variant_id' => $variant->id, 'quantity' => '2', 'name' => 'Item']], 'label' => 'Cliente de boné', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('sale');
    expect($suspended['status'])->toBe('suspended');
    expect(SaleOrder::query()->count())->toBe(0);
    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->sole()->reserved_quantity)->toBe('0.0000');

    $variant->update(['sale_price' => '18.00']);
    $cart = $this->postJson("/pos/sessions/{$session->id}/suspended/{$suspended['id']}/resume")->assertOk()->json('cart');
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart($cart['items'], [['method' => 'cash', 'amount' => '36.00']], ['suspended_sale_id' => $suspended['id']]))->assertCreated();

    $sale = PosSale::query()->sole();
    expect($sale)->status->value->toBe('completed')->total->toBe('36.00');
    $this->postJson("/pos/sessions/{$session->id}/suspended/{$sale->id}/resume")->assertJsonValidationErrors('suspended_sale_id');
});

test('cancelling a suspended or in-progress sale requires permission and keeps the history', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession]);
    $session = openPosSession($company);
    $cart = ['items' => [['description' => 'Bala', 'unit_price' => '1.00', 'quantity' => '3']]];

    $this->postJson("/pos/sessions/{$session->id}/cancel", ['cart' => $cart, 'reason' => 'Cliente desistiu', 'idempotency_key' => (string) Str::uuid()])->assertJsonValidationErrors('authorization');

    actingAsPosMember($company, user: User::query()->find($session->user_id));
    CompanyMembership::query()->where('user_id', $session->user_id)->update(['permissions' => [PosPermission::Operate->value, PosPermission::OpenSession->value, PosPermission::CancelSales->value], 'role' => 'operator']);
    $this->postJson("/pos/sessions/{$session->id}/cancel", ['cart' => $cart, 'reason' => 'Cliente desistiu', 'idempotency_key' => (string) Str::uuid()])->assertOk();

    expect(PosSale::query()->sole())->status->value->toBe('cancelled')->cancellation_reason->toBe('Cliente desistiu')->cart->not->toBeNull();
    expect(SaleOrder::query()->count())->toBe(0);
});

test('a partial return uses the sales return, restocks and refunds cash from the open drawer', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, alsoEnable: ['products']);
    $variant = sellableVariant($company, '10.00', stock: '10', averageCost: '4.00');
    $session = openPosSession($company, '100.00');
    $saleId = $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['product_variant_id' => $variant->id, 'quantity' => '3']], [['method' => 'cash', 'amount' => '30.00']]))->assertCreated()->json('sale.id');
    $item = SaleOrder::query()->sole()->items()->sole();

    $this->post("/pos/sales/{$saleId}/returns", ['items' => [['sale_order_item_id' => $item->id, 'quantity' => '1']], 'restock' => true, 'reason' => 'Defeito', 'refund_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

    expect(SaleReturn::query()->sole())->total_value->toBe('10.00');
    expect(PosRefund::query()->sole())->amount->toBe('10.00')->method->value->toBe('cash');
    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->sole()->quantity)->toBe('8.0000');
    expect($this->getJson("/pos/sessions/{$session->id}/summary")->json('expected_cash'))->toBe('120.00');

    $this->post("/pos/sales/{$saleId}/returns", ['reason' => 'Devolução do restante', 'refund_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    expect(PosSale::query()->find($saleId)->refunded_total)->toBe('30.00');
    $this->post("/pos/sales/{$saleId}/returns", ['reason' => 'De novo', 'refund_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('items');
});

test('returns without permission need a supervisor', function () {
    $company = Company::factory()->create();
    actingAsPosMember($company, [PosPermission::Operate, PosPermission::OpenSession]);
    $session = openPosSession($company);
    $sale = posSell($session, '10.00');

    $this->post("/pos/sales/{$sale->id}/returns", ['reason' => 'Troca', 'refund_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('authorization');
    expect(SaleReturn::query()->count())->toBe(0);
});
