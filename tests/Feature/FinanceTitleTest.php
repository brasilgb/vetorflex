<?php

use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceAttachment;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

test('a finance-only company launches a receivable, receives it partially and then fully', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company, '50.00');

    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();
    expect($title)->status->toBe(TitleStatus::Pending)->amount->toBe('100.00')->number->toBe(1);

    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '40.00'))->assertSessionHasNoErrors();
    expect($title->refresh())->status->toBe(TitleStatus::Partial)->settled_principal->toBe('40.00');

    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '60.00', ['interest' => '2.50', 'fine' => '1.00', 'discount' => '0.50']))->assertSessionHasNoErrors();

    expect($title->refresh())->status->toBe(TitleStatus::Settled)->settled_principal->toBe('100.00');
    expect(FinanceSettlement::query()->latest('id')->first()->amount)->toBe('63.00');
    expect(app(FinanceLedger::class)->balanceOf($account))->toBe('153.00');
    $this->get('/sales')->assertForbidden();
    $this->get('/purchases')->assertForbidden();
});

test('installments split the total with the remaining cents in the last one', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);

    $this->post('/finance/titles', financeTitleData('payable', ['amount' => '100.00', 'installments' => 3]))->assertSessionHasNoErrors();

    $titles = FinanceTitle::query()->orderBy('installment_number')->get();
    expect($titles->pluck('amount')->all())->toBe(['33.33', '33.33', '33.34']);
    expect($titles->pluck('installment_count')->unique()->all())->toBe([3]);
    expect($titles->pluck('group_key')->unique())->toHaveCount(1);
});

test('a settlement cannot exceed the open balance and is idempotent', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company);
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();

    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '100.01'))->assertSessionHasErrors('principal');

    $data = settlementData($account, '30.00');
    $this->post("/finance/titles/{$title->id}/settlements", $data)->assertSessionHasNoErrors();
    $this->post("/finance/titles/{$title->id}/settlements", $data)->assertSessionHasNoErrors();
    $this->post("/finance/titles/{$title->id}/settlements", [...$data, 'principal' => '31.00'])->assertSessionHasErrors('idempotency_key');

    expect(FinanceSettlement::query()->count())->toBe(1);
    expect(FinanceMovement::query()->count())->toBe(1);
    expect($title->refresh()->settled_principal)->toBe('30.00');
});

test('reversing a settlement keeps the original and posts a compensating movement', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company, '10.00');
    $this->post('/finance/titles', financeTitleData('payable'))->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();
    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '100.00'))->assertSessionHasNoErrors();
    $settlement = FinanceSettlement::query()->sole();
    expect(app(FinanceLedger::class)->balanceOf($account))->toBe('-90.00');

    $this->post("/finance/settlements/{$settlement->id}/reverse", ['reason' => 'Pagamento lançado em duplicidade'])->assertSessionHasNoErrors();
    $this->post("/finance/settlements/{$settlement->id}/reverse", ['reason' => 'De novo'])->assertSessionHasErrors();

    expect($settlement->refresh()->status->value)->toBe('reversed');
    expect($title->refresh())->status->toBe(TitleStatus::Pending)->settled_principal->toBe('0.00');
    expect(FinanceMovement::query()->count())->toBe(2);
    expect(FinanceMovement::query()->whereNotNull('reversal_of_id')->sole()->amount)->toBe('100.00');
    expect(app(FinanceLedger::class)->balanceOf($account))->toBe('10.00');
});

test('cancelling keeps settled values and only the open balance is cancelled', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company);
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();
    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '25.00'))->assertSessionHasNoErrors();

    $this->post("/finance/titles/{$title->id}/cancel", ['reason' => 'Cliente desistiu do restante'])->assertSessionHasNoErrors();

    expect($title->refresh())->settled_principal->toBe('25.00')->cancelled_amount->toBe('75.00');
    expect(app(FinanceLedger::class)->balanceOf($account))->toBe('25.00');
    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '1.00'))->assertSessionHasErrors('principal');
});

test('overdue status is derived from the due date and the open balance', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $this->post('/finance/titles', financeTitleData('receivable', ['due_date' => now()->subDays(3)->format('Y-m-d')]))->assertSessionHasNoErrors();

    $this->get('/finance/receivables?status=overdue')->assertOk()
        ->assertInertia(fn ($page) => $page->component('finance/titles/index')->has('titles.data', 1)->where('titles.data.0.is_overdue', true)->where('totals.overdue', '100.00'));
});

test('renegotiation replaces open titles with new installments and keeps traceability', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $this->post('/finance/titles', financeTitleData('receivable', ['amount' => '60.00']))->assertSessionHasNoErrors();
    $this->post('/finance/titles', financeTitleData('receivable', ['amount' => '40.00']))->assertSessionHasNoErrors();
    $ids = FinanceTitle::query()->pluck('id')->all();

    $this->post('/finance/titles/renegotiate', [
        'titles' => $ids,
        'schedule' => [['due_date' => now()->addMonth()->format('Y-m-d'), 'amount' => '55.00'], ['due_date' => now()->addMonths(2)->format('Y-m-d'), 'amount' => '55.00']],
        'reason' => 'Acordo com juros',
    ])->assertSessionHasNoErrors();

    expect(FinanceTitle::query()->whereIn('id', $ids)->pluck('status')->unique()->all())->toBe([TitleStatus::Renegotiated]);
    expect(FinanceTitle::query()->where('origin', 'renegotiation')->sum('amount'))->toEqual('110.00');
});

test('titles of another company are not found', function () {
    $other = Company::factory()->create();
    actingAsFinanceMember($other);
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $foreign = FinanceTitle::query()->sole();
    $foreignAccount = financeAccount($other);

    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $account = financeAccount($company, name: 'Banco');

    $this->get("/finance/titles/{$foreign->id}")->assertNotFound();
    $this->post("/finance/titles/{$foreign->id}/settlements", settlementData($account, '10.00'))->assertNotFound();
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $own = FinanceTitle::query()->where('company_id', $company->id)->sole();
    $this->post("/finance/titles/{$own->id}/settlements", settlementData($foreignAccount, '10.00'))->assertSessionHasErrors('finance_account_id');
    expect($own->number)->toBe(1);
});

test('permissions are checked per operation and title kind', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company, [FinancePermission::ViewReceivables, FinancePermission::ManageReceivables]);
    $account = financeAccount($company);

    $this->get('/finance/receivables')->assertOk();
    $this->get('/finance/payables')->assertForbidden();
    $this->get('/finance')->assertForbidden();
    $this->post('/finance/titles', financeTitleData('payable'))->assertForbidden();
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();
    $this->post("/finance/titles/{$title->id}/settlements", settlementData($account, '10.00'))->assertForbidden();
    $this->post("/finance/titles/{$title->id}/cancel", ['reason' => 'Teste'])->assertForbidden();
    $this->get('/finance/accounts')->assertForbidden();
    $this->post('/finance/transfers', [])->assertForbidden();
});

test('sales permissions do not grant finance access', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, [SalePermission::View]);

    $this->get('/finance')->assertForbidden();
    $this->get('/finance/receivables')->assertForbidden();
    $this->post('/finance/titles', financeTitleData() + ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
});

test('attachments accept documents only and stay inside the company', function () {
    Storage::fake('local');
    $other = Company::factory()->create();
    actingAsFinanceMember($other);
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $foreignTitle = FinanceTitle::query()->sole();
    $this->post("/finance/titles/{$foreignTitle->id}/attachments", ['file' => UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
    $foreign = FinanceAttachment::query()->sole();

    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $this->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->where('company_id', $company->id)->sole();

    $this->post("/finance/titles/{$title->id}/attachments", ['file' => UploadedFile::fake()->create('programa.exe', 20, 'application/x-msdownload')])->assertSessionHasErrors('file');
    $this->post("/finance/titles/{$title->id}/attachments", ['file' => UploadedFile::fake()->create('boleto.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
    $own = FinanceAttachment::query()->where('company_id', $company->id)->sole();

    $this->get("/finance/attachments/{$own->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get("/finance/attachments/{$foreign->id}")->assertNotFound();
});
