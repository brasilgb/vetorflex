<?php

use App\Http\Controllers\Finance\FinanceAccountController;
use App\Http\Controllers\Finance\FinanceAttachmentController;
use App\Http\Controllers\Finance\FinanceCashFlowController;
use App\Http\Controllers\Finance\FinanceCategoryController;
use App\Http\Controllers\Finance\FinanceDashboardController;
use App\Http\Controllers\Finance\FinanceImportController;
use App\Http\Controllers\Finance\FinanceMovementController;
use App\Http\Controllers\Finance\FinancePartyController;
use App\Http\Controllers\Finance\FinanceReconciliationController;
use App\Http\Controllers\Finance\FinanceRecurrenceController;
use App\Http\Controllers\Finance\FinanceReportController;
use App\Http\Controllers\Finance\FinanceSettlementController;
use App\Http\Controllers\Finance\FinanceTitleController;
use App\Http\Controllers\Finance\FinanceTransferController;
use Illuminate\Support\Facades\Route;

/*
| Rotas do Financeiro. Permissões que dependem do tipo do título (a receber ou a pagar) são verificadas nos
| controladores, que também confirmam a habilitação do módulo.
*/
Route::prefix('finance')->name('finance.')->middleware(['tenant', 'tenant.records'])->group(function (): void {
    $permission = fn (string $ability): string => "module.permission:finance,finance.{$ability}";

    Route::get('/', FinanceDashboardController::class)->middleware($permission('view_dashboard'))->name('dashboard');

    Route::get('{kind}', [FinanceTitleController::class, 'index'])->whereIn('kind', ['receivables', 'payables'])->name('titles.index');
    Route::get('{kind}/export', [FinanceTitleController::class, 'export'])->whereIn('kind', ['receivables', 'payables'])->middleware($permission('export'))->name('titles.export');
    Route::post('titles', [FinanceTitleController::class, 'store'])->name('titles.store');
    Route::post('titles/renegotiate', [FinanceTitleController::class, 'renegotiate'])->name('titles.renegotiate');
    Route::get('titles/{title}', [FinanceTitleController::class, 'show'])->name('titles.show');
    Route::put('titles/{title}', [FinanceTitleController::class, 'update'])->name('titles.update');
    Route::post('titles/{title}/cancel', [FinanceTitleController::class, 'cancel'])->name('titles.cancel');
    Route::post('titles/{title}/settlements', [FinanceSettlementController::class, 'store'])->name('settlements.store');
    Route::post('settlements/{settlement}/reverse', [FinanceSettlementController::class, 'reverse'])->name('settlements.reverse');
    Route::post('titles/{title}/attachments', [FinanceAttachmentController::class, 'store'])->name('attachments.store');
    Route::get('attachments/{attachment}', [FinanceAttachmentController::class, 'show'])->name('attachments.show');

    Route::get('parties/lookup', [FinancePartyController::class, 'lookup'])->name('parties.lookup');
    Route::post('parties', [FinancePartyController::class, 'store'])->name('parties.store');

    Route::middleware($permission('view_accounts'))->group(function () use ($permission): void {
        Route::get('accounts', [FinanceAccountController::class, 'index'])->name('accounts.index');
        Route::get('movements', [FinanceMovementController::class, 'index'])->name('movements.index');
        Route::get('movements/export', [FinanceMovementController::class, 'export'])->middleware($permission('export'))->name('movements.export');
        Route::get('transfers', [FinanceTransferController::class, 'index'])->name('transfers.index');
    });
    Route::middleware($permission('manage_accounts'))->group(function (): void {
        Route::post('accounts', [FinanceAccountController::class, 'store'])->name('accounts.store');
        Route::put('accounts/{account}', [FinanceAccountController::class, 'update'])->name('accounts.update');
        Route::patch('accounts/{account}/toggle', [FinanceAccountController::class, 'toggle'])->name('accounts.toggle');
    });
    Route::post('accounts/{account}/adjust', [FinanceAccountController::class, 'adjust'])->middleware($permission('adjust_balances'))->name('accounts.adjust');

    Route::middleware($permission('transfer'))->group(function (): void {
        Route::post('transfers', [FinanceTransferController::class, 'store'])->name('transfers.store');
        Route::post('transfers/{transfer}/reverse', [FinanceTransferController::class, 'reverse'])->name('transfers.reverse');
    });

    Route::middleware($permission('reconcile'))->group(function (): void {
        Route::get('reconciliation', [FinanceReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::post('reconciliation/{account}', [FinanceReconciliationController::class, 'store'])->name('reconciliation.store');
        Route::post('movements/{movement}/unreconcile', [FinanceReconciliationController::class, 'destroy'])->name('reconciliation.destroy');
    });

    Route::middleware($permission('manage_recurrences'))->group(function (): void {
        Route::get('recurrences', [FinanceRecurrenceController::class, 'index'])->name('recurrences.index');
        Route::post('recurrences', [FinanceRecurrenceController::class, 'store'])->name('recurrences.store');
        Route::post('recurrences/{recurrence}/end', [FinanceRecurrenceController::class, 'end'])->name('recurrences.end');
    });

    Route::middleware($permission('view_reports'))->group(function () use ($permission): void {
        Route::get('cash-flow', FinanceCashFlowController::class)->name('cash-flow');
        Route::get('reports', [FinanceReportController::class, 'index'])->name('reports.index');
        Route::get('reports/csv', [FinanceReportController::class, 'csv'])->middleware($permission('export'))->name('reports.csv');
        Route::get('reports/pdf', [FinanceReportController::class, 'pdf'])->middleware($permission('export'))->name('reports.pdf');
    });

    Route::middleware($permission('manage_categories'))->group(function (): void {
        Route::get('settings', [FinanceCategoryController::class, 'index'])->name('settings');
        Route::post('categories', [FinanceCategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [FinanceCategoryController::class, 'update'])->name('categories.update');
    });

    Route::middleware($permission('import_documents'))->group(function (): void {
        Route::get('import', [FinanceImportController::class, 'index'])->name('import.index');
        Route::post('import', [FinanceImportController::class, 'store'])->name('import.store');
    });
});
