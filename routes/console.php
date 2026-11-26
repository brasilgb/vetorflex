<?php

use App\Modules\Products\Models\ProductImport;
use App\Modules\Purchases\Models\SupplierImport;
use App\Modules\Sales\Services\SaleQuoteService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune', ['--model' => [ProductImport::class, SupplierImport::class]])->daily();

Artisan::command('sales:expire-quotes', function (SaleQuoteService $quotes, TenantContext $tenants) {
    $expired = 0;

    foreach (Company::query()->where('status', 'active')->orderBy('id')->get() as $company) {
        if ($tenants->enabledModulesFor($company)->contains('identifier', 'sales')) {
            $expired += $quotes->expireDue($company, null, now($company->timezone)->format('Y-m-d'));
        }
    }

    $this->info("Orçamentos expirados: {$expired}.");
})->purpose('Expira orçamentos emitidos com validade vencida');

Schedule::command('sales:expire-quotes')->dailyAt('00:10');
