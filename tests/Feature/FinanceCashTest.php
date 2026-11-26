<?php

use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceRecurrence;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Models\FinanceTransfer;
use App\Modules\Finance\Queries\CashFlow;
use App\Modules\Finance\Queries\FinanceDashboard;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\Finance\Services\FinanceRecurrenceService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

test('accounts are created with an opening balance that cannot be edited into the current balance', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);

    $this->post('/finance/accounts', ['name' => 'Banco do Bairro', 'type' => 'checking', 'institution' => 'Banco X', 'opening_balance' => '1000.00', 'opening_date' => now()->subDays(10)->format('Y-m-d')])->assertSessionHasNoErrors();
    $this->post('/finance/accounts', ['name' => 'Banco do Bairro', 'type' => 'cash', 'opening_balance' => '0', 'opening_date' => now()->format('Y-m-d')])->assertSessionHasErrors('name');
    $account = FinanceAccount::query()->sole();
    $this->put("/finance/accounts/{$account->id}", ['name' => 'Banco do Bairro', 'type' => 'checking', 'opening_balance' => '1000.00', 'opening_date' => $account->opening_date->format('Y-m-d'), 'balance' => '5.00'])->assertSessionHasNoErrors();

    expect(app(FinanceLedger::class)->balanceOf($account->refresh()))->toBe('1000.00');
    $this->get('/finance/accounts')->assertOk()->assertInertia(fn ($page) => $page->component('finance/accounts')->where('accounts.0.balance', '1000.00'));
});

test('a balance adjustment requires permission and justification and is not operational', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company, [FinancePermission::ViewAccounts, FinancePermission::ManageAccounts]);
    $account = financeAccount($company, '100.00');
    $data = ['balance' => '80.00', 'date' => now()->format('Y-m-d'), 'reason' => 'Diferença de caixa', 'idempotency_key' => (string) Str::uuid()];

    $this->post("/finance/accounts/{$account->id}/adjust", $data)->assertForbidden();

    actingAsFinanceMember($company);
    $this->post("/finance/accounts/{$account->id}/adjust", [...$data, 'reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/finance/accounts/{$account->id}/adjust", $data)->assertSessionHasNoErrors();
    $this->post("/finance/accounts/{$account->id}/adjust", $data)->assertSessionHasNoErrors();

    $movement = FinanceMovement::query()->sole();
    expect($movement)->amount->toBe('-20.00')->type->value->toBe('adjustment')->is_operational->toBeFalse();
    expect(app(FinanceLedger::class)->balanceOf($account))->toBe('80.00');
});

test('a transfer moves money between accounts without counting as income or expense, and can be reversed', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $cash = financeAccount($company, '500.00', 'Caixa');
    $bank = financeAccount($company, '0.00', 'Banco');
    $data = ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => '200.00', 'transferred_at' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()];

    $this->post('/finance/transfers', [...$data, 'to_account_id' => $cash->id])->assertSessionHasErrors('to_account_id');
    $this->post('/finance/transfers', $data)->assertSessionHasNoErrors();
    $this->post('/finance/transfers', $data)->assertSessionHasNoErrors();

    $transfer = FinanceTransfer::query()->sole();
    expect(FinanceMovement::query()->where('finance_transfer_id', $transfer->id)->count())->toBe(2);
    $ledger = app(FinanceLedger::class);
    expect($ledger->balanceOf($cash))->toBe('300.00')->and($ledger->balanceOf($bank))->toBe('200.00');

    $dashboard = app(FinanceDashboard::class)->for($company, now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d'), null);
    expect($dashboard)->received->toBe('0.00')->paid->toBe('0.00')->available_balance->toBe('500.00');
    $flow = app(CashFlow::class)->for($company, now()->startOfMonth()->format('Y-m-d'), now()->format('Y-m-d'), 'month');
    expect($flow['totals'])->realized_in->toBe('0.00')->realized_out->toBe('0.00');

    $this->post("/finance/transfers/{$transfer->id}/reverse", ['reason' => 'Conta errada'])->assertSessionHasNoErrors();
    expect($transfer->refresh()->status)->toBe('reversed');
    expect($ledger->balanceOf($cash))->toBe('500.00')->and($ledger->balanceOf($bank))->toBe('0.00');
    expect(FinanceMovement::query()->count())->toBe(4);
});

test('a transfer between accounts of different companies is rejected', function () {
    $other = Company::factory()->create();
    $foreign = financeAccount($other, '100.00', 'Alheia');
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $cash = financeAccount($company, '100.00');

    $this->post('/finance/transfers', ['from_account_id' => $cash->id, 'to_account_id' => $foreign->id, 'amount' => '10.00', 'transferred_at' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasErrors();
    expect(FinanceTransfer::query()->count())->toBe(0);
});

test('recurrences generate titles idempotently, without settling them, until ended', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);

    $this->post('/finance/recurrences', ['kind' => 'payable', 'description' => 'Aluguel da loja', 'amount' => '1500.00', 'frequency' => 'weekly', 'start_date' => now()->format('Y-m-d')])->assertSessionHasNoErrors();
    $recurrence = FinanceRecurrence::query()->sole();
    $generated = FinanceTitle::query()->where('finance_recurrence_id', $recurrence->id)->count();
    expect($generated)->toBeGreaterThanOrEqual(4);
    expect(FinanceTitle::query()->where('settled_principal', '>', 0)->count())->toBe(0);

    $service = app(FinanceRecurrenceService::class);
    expect($service->generateAll($company))->toBe(0);
    $this->artisan('finance:generate-recurrences')->assertSuccessful();
    expect(FinanceTitle::query()->count())->toBe($generated);

    $this->post("/finance/recurrences/{$recurrence->id}/end")->assertSessionHasNoErrors();
    $this->travel(60)->days();
    expect($service->generateAll($company))->toBe(0);
    expect(FinanceTitle::query()->count())->toBe($generated);
});

test('reconciliation marks movements and undoing it requires a reason', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company);
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $this->post('/finance/titles/'.FinanceTitle::query()->sole()->id.'/settlements', settlementData($account, '100.00'))->assertSessionHasNoErrors();
    $movement = FinanceMovement::query()->sole();

    $this->get("/finance/reconciliation?account={$account->id}")->assertOk()->assertInertia(fn ($page) => $page->component('finance/reconciliation')->where('account.unreconciled', 1));
    $this->post("/finance/reconciliation/{$account->id}", ['movements' => [$movement->id]])->assertSessionHasNoErrors();
    expect($movement->refresh()->reconciled_at)->not->toBeNull();

    $this->post("/finance/movements/{$movement->id}/unreconcile", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/finance/movements/{$movement->id}/unreconcile", ['reason' => 'Valor diferente do extrato'])->assertSessionHasNoErrors();
    expect($movement->refresh()->reconciled_at)->toBeNull();
});

test('cash flow counts only the open part of partially settled titles', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company, '0.00');
    $this->post('/finance/titles', financeTitleData('receivable', ['amount' => '100.00', 'due_date' => now()->addDays(3)->format('Y-m-d')]))->assertSessionHasNoErrors();
    $this->post('/finance/titles', financeTitleData('payable', ['amount' => '30.00', 'due_date' => now()->subDays(2)->format('Y-m-d')]))->assertSessionHasNoErrors();
    $receivable = FinanceTitle::query()->where('kind', 'receivable')->sole();
    $this->post("/finance/titles/{$receivable->id}/settlements", settlementData($account, '40.00'))->assertSessionHasNoErrors();

    $flow = app(CashFlow::class)->for($company, now()->startOfMonth()->format('Y-m-d'), now()->addDays(30)->format('Y-m-d'), 'day');

    expect($flow['totals'])->realized_in->toBe('40.00')->forecast_in->toBe('60.00')->forecast_out->toBe('30.00');
    expect($flow)->opening->toBe('0.00')->closing->toBe('70.00');
    $this->get('/finance/cash-flow?granularity=month')->assertOk()->assertInertia(fn ($page) => $page->component('finance/cash-flow'));
});

test('the dashboard separates forecast, realized and overdue values', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company, '10.00');
    $this->post('/finance/titles', financeTitleData('receivable', ['amount' => '100.00', 'due_date' => now()->subDay()->format('Y-m-d')]))->assertSessionHasNoErrors();
    $this->post('/finance/titles', financeTitleData('payable', ['amount' => '50.00']))->assertSessionHasNoErrors();
    $payable = FinanceTitle::query()->where('kind', 'payable')->sole();
    $this->post("/finance/titles/{$payable->id}/settlements", settlementData($account, '20.00'))->assertSessionHasNoErrors();

    $this->get('/finance')->assertOk()->assertInertia(fn ($page) => $page->component('finance/dashboard')
        ->where('dashboard.to_receive', '100.00')
        ->where('dashboard.overdue_receivable', '100.00')
        ->where('dashboard.to_pay', '30.00')
        ->where('dashboard.received', '0.00')
        ->where('dashboard.paid', '20.00')
        ->where('dashboard.result', '-20.00')
        ->where('dashboard.available_balance', '-10.00'));
});
