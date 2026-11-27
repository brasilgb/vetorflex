<?php

use App\Http\Controllers\Fiscal\FiscalDashboardController;
use App\Http\Controllers\Fiscal\FiscalDocumentController;
use App\Http\Controllers\Fiscal\FiscalItemController;
use App\Http\Controllers\Fiscal\FiscalReportController;
use App\Http\Controllers\Fiscal\FiscalSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Rotas do módulo Documentos Fiscais (`fiscal`). Toda rota exige o módulo habilitado e `fiscal.view`; as operações
| exigem, além disso, a permissão individual. A empresa vem sempre do contexto do usuário, nunca da requisição.
*/
Route::prefix('fiscal')->name('fiscal.')->middleware(['tenant', 'tenant.records', 'module.permission:fiscal,fiscal.view'])->group(function (): void {
    $permission = fn (string $ability): string => "module.permission:fiscal,fiscal.{$ability}";

    Route::get('/', [FiscalDashboardController::class, 'index'])->name('dashboard');
    Route::post('reconcile', [FiscalDashboardController::class, 'reconcile'])->middleware($permission('reprocess'))->name('reconcile');
    Route::post('webhook-events/{event}/reprocess', [FiscalDashboardController::class, 'reprocess'])->middleware($permission('reprocess'))->name('webhook-events.reprocess');

    Route::get('documents', [FiscalDocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/export', [FiscalDocumentController::class, 'export'])->middleware($permission('export'))->name('documents.export');
    Route::middleware($permission('create_draft'))->group(function (): void {
        Route::get('documents/create', [FiscalDocumentController::class, 'create'])->name('documents.create');
        Route::post('documents', [FiscalDocumentController::class, 'store'])->name('documents.store');
    });
    Route::get('documents/{document}', [FiscalDocumentController::class, 'show'])->name('documents.show');
    Route::middleware($permission('edit'))->group(function (): void {
        Route::get('documents/{document}/edit', [FiscalDocumentController::class, 'edit'])->name('documents.edit');
        Route::put('documents/{document}', [FiscalDocumentController::class, 'update'])->name('documents.update');
        Route::delete('documents/{document}', [FiscalDocumentController::class, 'destroy'])->name('documents.destroy');
    });
    Route::post('documents/{document}/issue', [FiscalDocumentController::class, 'issue'])->middleware($permission('issue'))->name('documents.issue');
    Route::post('documents/{document}/refresh', [FiscalDocumentController::class, 'refresh'])->name('documents.refresh');
    Route::post('documents/{document}/cancel', [FiscalDocumentController::class, 'cancel'])->middleware($permission('cancel'))->name('documents.cancel');
    Route::get('documents/{document}/files/{format}', [FiscalDocumentController::class, 'download'])->middleware($permission('download'))->whereIn('format', ['xml', 'pdf'])->name('documents.download');

    Route::get('items', [FiscalItemController::class, 'index'])->name('items.index');
    Route::put('items/{product}', [FiscalItemController::class, 'update'])->middleware($permission('edit'))->name('items.update');

    Route::middleware($permission('view_reports'))->group(function () use ($permission): void {
        Route::get('reports', [FiscalReportController::class, 'index'])->name('reports.index');
        Route::get('reports/csv', [FiscalReportController::class, 'csv'])->middleware($permission('export'))->name('reports.csv');
    });

    Route::middleware($permission('manage_settings'))->group(function (): void {
        Route::get('settings', [FiscalSettingsController::class, 'edit'])->name('settings');
        Route::post('settings', [FiscalSettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/confirm', [FiscalSettingsController::class, 'confirm'])->name('settings.confirm');
        Route::post('settings/register', [FiscalSettingsController::class, 'register'])->name('settings.register');
        Route::post('settings/certificate', [FiscalSettingsController::class, 'certificate'])->name('settings.certificate');
    });
});
