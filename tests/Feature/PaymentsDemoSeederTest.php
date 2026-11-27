<?php

use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Pos\Models\PosPayment;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\DatabaseSeeder;

test('the demo seed creates the payments scenarios once, with a simulated provider', function () {
    $this->seed(DatabaseSeeder::class);
    $counts = fn (): array => [PaymentCharge::query()->count(), PaymentTransaction::query()->count(), FinanceSettlement::query()->count(), PosPayment::query()->where('is_provider_confirmed', true)->count()];
    $first = $counts();
    $this->seed(DatabaseSeeder::class);

    expect($counts())->toBe($first);

    $only = Company::query()->where('slug', 'estudio-somente-pagamentos')->sole();
    expect(PaymentCharge::query()->where('company_id', $only->id)->pluck('status')->map->value->sort()->values()->all())->toBe(['cancelled', 'paid', 'pending']);

    foreach (['loja-pagamentos-vendas', 'consultoria-pagamentos-financeiro', 'cafe-pagamentos-pdv'] as $slug) {
        $company = Company::query()->where('slug', $slug)->sole();
        expect(PaymentCharge::query()->where('company_id', $company->id)->where('status', ChargeStatus::Paid)->count())->toBe(1);
    }

    $finance = Company::query()->where('slug', 'consultoria-pagamentos-financeiro')->sole();
    expect(FinanceSettlement::query()->where('company_id', $finance->id)->count())->toBe(1);
    $pos = Company::query()->where('slug', 'cafe-pagamentos-pdv')->sole();
    expect(PosPayment::query()->where('company_id', $pos->id)->where('is_provider_confirmed', true)->count())->toBe(1);
});
