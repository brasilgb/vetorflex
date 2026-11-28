<?php

use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

/** Empresa com conta, título liquidado em parte, título vencido e transferência. */
function financeScenario(): Company
{
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $cash = financeAccount($company, '100.00', 'Caixa');
    $bank = financeAccount($company, '0.00', 'Banco');
    test()->post('/finance/titles', financeTitleData())->assertSessionHasNoErrors();
    test()->post('/finance/titles', financeTitleData('payable', ['due_date' => now()->subDays(4)->format('Y-m-d')]))->assertSessionHasNoErrors();
    test()->post('/finance/titles/'.FinanceTitle::query()->where('kind', 'receivable')->sole()->id.'/settlements', settlementData($cash, '40.00'))->assertSessionHasNoErrors();
    test()->post('/finance/transfers', ['from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => '20.00', 'transferred_at' => now()->format('Y-m-d'), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

    return $company;
}

test('every finance page renders with real data', function (string $url, string $component) {
    financeScenario();

    $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/finance', 'finance/dashboard'],
    ['/finance/receivables', 'finance/titles/index'],
    ['/finance/payables', 'finance/titles/index'],
    ['/finance/accounts', 'finance/accounts'],
    ['/finance/movements', 'finance/movements'],
    ['/finance/transfers', 'finance/transfers'],
    ['/finance/cash-flow', 'finance/cash-flow'],
    ['/finance/recurrences', 'finance/recurrences'],
    ['/finance/reconciliation', 'finance/reconciliation'],
    ['/finance/reports', 'finance/reports'],
    ['/finance/settings', 'finance/settings'],
    ['/finance/import', 'finance/import'],
]);

test('the title page shows settlements and history', function () {
    financeScenario();
    $title = FinanceTitle::query()->where('kind', 'receivable')->sole();

    $this->get("/finance/titles/{$title->id}")->assertOk()->assertInertia(fn ($page) => $page->component('finance/titles/show')
        ->has('title.settlements', 1)->where('title.open', '60.00')->where('title.status', 'partial'));
});

test('every report builds and exports to CSV and PDF', function (string $type) {
    financeScenario();

    $this->get("/finance/reports?type={$type}")->assertOk()->assertInertia(fn ($page) => $page->component('finance/reports')->where('report.type', $type));
    expect($this->get("/finance/reports/csv?type={$type}")->assertOk()->streamedContent())->toStartWith("\u{FEFF}");
    expect($this->get("/finance/reports/pdf?type={$type}")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent())->toStartWith('%PDF');
})->with(array_keys(FinanceReports::TYPES));

test('the settled report lists the partially received title and the overdue report the late payable', function () {
    financeScenario();

    $this->get('/finance/reports?type=settled')->assertInertia(fn ($page) => $page->has('report.rows', 1)->where('report.totals.settled', '40.00'));
    $this->get('/finance/reports?type=overdue')->assertInertia(fn ($page) => $page->has('report.rows', 1)->where('report.totals.open', '100.00'));
});

test('csv exports neutralize spreadsheet formulas', function () {
    $company = Company::factory()->create();
    actingAsFinanceMember($company);
    $this->post('/finance/titles', financeTitleData('receivable', ['description' => '=HYPERLINK("http://x")']))->assertSessionHasNoErrors();

    $content = $this->get('/finance/receivables/export?status=all')->assertOk()->streamedContent();

    expect($content)->not->toContain(';=HYPERLINK')->not->toContain("\n=HYPERLINK");
});
