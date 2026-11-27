<?php

use App\Http\Controllers\Payments\PaymentChargeController;
use App\Http\Controllers\Payments\PaymentReconciliationController;
use App\Http\Controllers\Payments\PaymentReportController;
use App\Http\Controllers\Payments\PaymentsDashboardController;
use App\Http\Controllers\Payments\PaymentSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Rotas do módulo Pagamentos (`payments`). Toda rota exige o módulo habilitado e `payments.view`; as operações exigem,
| além disso, a permissão individual. A empresa vem sempre do contexto do usuário, nunca da requisição.
*/
Route::prefix('payments')->name('payments.')->middleware(['tenant', 'tenant.records', 'module.permission:payments,payments.view'])->group(function (): void {
    $permission = fn (string $ability): string => "module.permission:payments,payments.{$ability}";

    Route::get('/', PaymentsDashboardController::class)->name('dashboard');

    Route::get('charges', [PaymentChargeController::class, 'index'])->name('charges.index');
    Route::get('charges/export', [PaymentChargeController::class, 'export'])->middleware($permission('export'))->name('charges.export');
    Route::middleware($permission('create'))->group(function (): void {
        Route::get('charges/create', [PaymentChargeController::class, 'create'])->name('charges.create');
        Route::post('charges', [PaymentChargeController::class, 'store'])->name('charges.store');
        Route::post('charges/{charge}/issue', [PaymentChargeController::class, 'issue'])->name('charges.issue');
    });
    Route::get('charges/{charge}', [PaymentChargeController::class, 'show'])->name('charges.show');
    Route::post('charges/{charge}/sync', [PaymentChargeController::class, 'sync'])->name('charges.sync');
    Route::post('charges/{charge}/cancel', [PaymentChargeController::class, 'cancel'])->middleware($permission('cancel'))->name('charges.cancel');
    Route::post('charges/{charge}/refunds', [PaymentChargeController::class, 'refund'])->middleware($permission('refund'))->name('charges.refunds.store');

    Route::middleware($permission('reconcile'))->group(function (): void {
        Route::get('reconciliation', [PaymentReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::post('reconciliation', [PaymentReconciliationController::class, 'reconcile'])->name('reconciliation.run');
    });
    Route::post('webhook-events/{event}/reprocess', [PaymentReconciliationController::class, 'reprocess'])->middleware($permission('reprocess'))->name('webhook-events.reprocess');

    Route::middleware($permission('view_reports'))->group(function () use ($permission): void {
        Route::get('reports', [PaymentReportController::class, 'index'])->name('reports.index');
        Route::get('reports/csv', [PaymentReportController::class, 'csv'])->middleware($permission('export'))->name('reports.csv');
    });

    Route::middleware($permission('manage_providers'))->group(function (): void {
        Route::get('settings', [PaymentSettingsController::class, 'edit'])->name('settings');
        Route::post('settings', [PaymentSettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/accounts/{account}/test', [PaymentSettingsController::class, 'test'])->name('settings.test');
        Route::post('settings/accounts/{account}/webhook', [PaymentSettingsController::class, 'webhook'])->name('settings.webhook');
    });
});
