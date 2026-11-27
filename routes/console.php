<?php

use App\Modules\Finance\Services\FinanceRecurrenceService;
use App\Modules\Fiscal\Services\FiscalDocumentService;
use App\Modules\Payments\Services\WebhookService;
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

Artisan::command('finance:generate-recurrences', function (FinanceRecurrenceService $recurrences, TenantContext $tenants) {
    $created = 0;

    foreach (Company::query()->where('status', 'active')->orderBy('id')->get() as $company) {
        if ($tenants->enabledModulesFor($company)->contains('identifier', 'finance')) {
            $created += $recurrences->generateAll($company);
        }
    }

    $this->info("Títulos recorrentes gerados: {$created}.");
})->purpose('Gera os títulos das receitas e despesas recorrentes, sem baixa automática');

Schedule::command('finance:generate-recurrences')->dailyAt('00:20');

Artisan::command('payments:reconcile', function (WebhookService $webhooks) {
    $result = $webhooks->reconcile(limit: (int) config('payments.reconcile_batch'));
    $this->info("Cobranças conciliadas: {$result['synced']}; falhas: {$result['failed']}; marcadas como vencidas: {$result['overdue']}.");
})->purpose('Consulta os provedores para conciliar cobranças em aberto e marca as vencidas');

Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('fiscal:reconcile', function (FiscalDocumentService $documents) {
    $result = $documents->reconcile();
    $this->info("Documentos fiscais consultados: {$result['checked']}; atualizados: {$result['changed']}; recolocados na fila: {$result['requeued']}.");
})->purpose('Consulta o provedor fiscal para documentos pendentes e recoloca na fila os que ficaram aguardando');

Schedule::command('fiscal:reconcile')->everyFiveMinutes()->withoutOverlapping();
