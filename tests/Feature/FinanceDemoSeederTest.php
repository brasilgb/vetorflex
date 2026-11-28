<?php

use App\Modules\Finance\Enums\TitleOrigin;
use App\Modules\Finance\Enums\TitleStatus;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceRecurrence;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Finance\Models\FinanceTransfer;
use App\Modules\Finance\Services\FinanceLedger;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\DatabaseSeeder;

test('the demo seed creates the finance scenarios once in the three module combinations', function () {
    $this->seed(DatabaseSeeder::class);
    $counts = fn (): array => [FinanceTitle::query()->count(), FinanceSettlement::query()->count(), FinanceMovement::query()->count(), FinanceTransfer::query()->count(), FinanceRecurrence::query()->count()];
    $first = $counts();
    $this->seed(DatabaseSeeder::class);

    expect($counts())->toBe($first);

    $office = Company::query()->where('slug', 'escritorio-somente-financeiro')->sole();
    $officeTitles = FinanceTitle::query()->where('company_id', $office->id);
    expect((clone $officeTitles)->where('status', TitleStatus::Partial)->count())->toBe(1);
    expect((clone $officeTitles)->where('status', TitleStatus::Settled)->count())->toBeGreaterThanOrEqual(3);
    expect((clone $officeTitles)->where('installment_count', 3)->count())->toBe(3);
    expect((clone $officeTitles)->where('origin', TitleOrigin::Recurrence)->exists())->toBeTrue();
    expect((clone $officeTitles)->whereIn('status', [TitleStatus::Pending, TitleStatus::Partial])->where('due_date', '<', now()->format('Y-m-d'))->exists())->toBeTrue();
    expect(FinanceSettlement::query()->where('company_id', $office->id)->where('status', 'reversed')->count())->toBe(1);
    expect(FinanceTransfer::query()->where('company_id', $office->id)->count())->toBe(1);
    expect(app(FinanceLedger::class)->balanceOf(FinanceAccount::query()->where('company_id', $office->id)->where('name', 'Caixa do escritório')->sole()))->toBe('145.00');

    foreach (['loja-somente-vendas', 'empresa-demonstracao'] as $slug) {
        $company = Company::query()->where('slug', $slug)->sole();
        expect(FinanceTitle::query()->where('company_id', $company->id)->where('origin', TitleOrigin::Sale)->exists())->toBeTrue();
    }

    $demo = Company::query()->where('slug', 'empresa-demonstracao')->sole();
    expect(FinanceTitle::query()->where('company_id', $demo->id)->where('origin', TitleOrigin::Purchase)->exists())->toBeTrue();
    expect(FinanceSettlement::query()->where('is_external', true)->exists())->toBeTrue();
});
