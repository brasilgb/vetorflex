<?php

use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

test('the demo seed creates the fiscal scenarios once, in homologation, with a simulated provider', function () {
    Storage::fake('fiscal');
    $this->seed(DatabaseSeeder::class);
    $first = FiscalDocument::query()->count();
    $this->seed(DatabaseSeeder::class);

    expect(FiscalDocument::query()->count())->toBe($first);
    expect(FiscalDocument::query()->where('environment', '!=', 'homologation')->exists())->toBeFalse();

    $only = Company::query()->where('slug', 'agencia-somente-fiscal')->sole();
    expect(FiscalDocument::query()->where('company_id', $only->id)->pluck('status')->map->value->sort()->values()->all())->toBe(['authorized', 'cancelled', 'draft', 'rejected']);

    foreach (['loja-fiscal-vendas' => ['nfe', 'sale_order'], 'padaria-fiscal-pdv' => ['nfce', 'pos_sale'], 'consultoria-fiscal-financeiro' => ['nfse', 'finance_title']] as $slug => [$type, $origin]) {
        $company = Company::query()->where('slug', $slug)->sole();
        expect(FiscalDocument::query()->where('company_id', $company->id)->sole())->type->value->toBe($type)->origin_type->toBe($origin)->status->value->toBe('authorized');
    }

    $finance = Company::query()->where('slug', 'consultoria-fiscal-financeiro')->sole();
    expect(FinanceSettlement::query()->where('company_id', $finance->id)->count())->toBe(0);
});
