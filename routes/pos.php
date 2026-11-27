<?php

use App\Http\Controllers\Pos\PosCatalogController;
use App\Http\Controllers\Pos\PosHomeController;
use App\Http\Controllers\Pos\PosPixController;
use App\Http\Controllers\Pos\PosRegisterController;
use App\Http\Controllers\Pos\PosReportController;
use App\Http\Controllers\Pos\PosSaleController;
use App\Http\Controllers\Pos\PosSessionController;
use App\Http\Controllers\Pos\PosSettingsController;
use App\Http\Controllers\Pos\PosTerminalController;
use Illuminate\Support\Facades\Route;

/*
| Rotas do PDV — Frente de Caixa. Toda rota exige o módulo `pos` habilitado e a permissão individual da operação;
| operações sensíveis aceitam, além disso, autorização de supervisor verificada nos serviços.
*/
Route::prefix('pos')->name('pos.')->middleware(['tenant', 'tenant.records'])->group(function (): void {
    $permission = fn (string $ability): string => "module.permission:pos,pos.{$ability}";

    Route::middleware($permission('operate'))->group(function (): void {
        Route::get('/', [PosHomeController::class, 'index'])->name('home');
        Route::get('sessions/{session}/register', [PosRegisterController::class, 'show'])->name('register');
        Route::post('sessions/{session}/sales', [PosRegisterController::class, 'finalize'])->name('sales.finalize');
        Route::post('sessions/{session}/suspend', [PosRegisterController::class, 'suspend'])->name('sales.suspend');
        Route::post('sessions/{session}/suspended/{sale}/resume', [PosRegisterController::class, 'resume'])->name('sales.resume');
        Route::post('sessions/{session}/cancel', [PosRegisterController::class, 'cancel'])->name('sales.cancel');
        Route::post('sessions/{session}/cash-movements', [PosRegisterController::class, 'cashMovement'])->name('cash-movements.store');
        Route::get('sessions/{session}/summary', [PosRegisterController::class, 'summary'])->name('sessions.summary');
        Route::post('sessions/{session}/pix', [PosPixController::class, 'store'])->name('pix.store');
        Route::get('sessions/{session}/pix/{charge}', [PosPixController::class, 'show'])->name('pix.show');
        Route::post('sessions/{session}/pix/{charge}/cancel', [PosPixController::class, 'cancel'])->name('pix.cancel');
        Route::get('sessions/{session}', [PosSessionController::class, 'show'])->name('sessions.show');

        Route::get('catalog/search', [PosCatalogController::class, 'search'])->name('catalog.search');
        Route::get('catalog/scan', [PosCatalogController::class, 'scan'])->name('catalog.scan');
        Route::get('customers/lookup', [PosCatalogController::class, 'customers'])->name('customers.lookup');
        Route::post('customers', [PosCatalogController::class, 'storeCustomer'])->name('customers.store');

        Route::get('sales/{sale}', [PosSaleController::class, 'show'])->name('sales.show');
        Route::post('sales/{sale}/returns', [PosSaleController::class, 'storeReturn'])->name('sales.returns.store');
        Route::get('sales/{sale}/receipt', [PosSaleController::class, 'receipt'])->name('sales.receipt');
        Route::get('sales/{sale}/receipt.pdf', [PosSaleController::class, 'pdf'])->name('sales.pdf');
    });

    Route::post('terminals/{terminal}/open', [PosHomeController::class, 'open'])->middleware($permission('open_session'))->name('sessions.open');
    Route::post('sessions/{session}/close', [PosSessionController::class, 'close'])->middleware($permission('close_session'))->name('sessions.close');

    Route::middleware($permission('view_sessions'))->group(function (): void {
        Route::get('sessions', [PosSessionController::class, 'index'])->name('sessions.index');
        Route::get('sales', [PosSaleController::class, 'index'])->name('sales.index');
    });

    Route::middleware($permission('manage_terminals'))->group(function (): void {
        Route::get('terminals', [PosTerminalController::class, 'index'])->name('terminals.index');
        Route::post('terminals', [PosTerminalController::class, 'store'])->name('terminals.store');
        Route::put('terminals/{terminal}', [PosTerminalController::class, 'update'])->name('terminals.update');
    });

    Route::middleware($permission('manage_settings'))->group(function (): void {
        Route::get('settings', [PosSettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [PosSettingsController::class, 'update'])->name('settings.update');
        Route::put('favorites', [PosSettingsController::class, 'favorites'])->name('favorites.update');
        Route::post('items', [PosCatalogController::class, 'storeItem'])->name('items.store');
    });

    Route::middleware($permission('view_reports'))->group(function (): void {
        Route::get('reports', [PosReportController::class, 'index'])->name('reports.index');
        Route::get('reports/csv', [PosReportController::class, 'csv'])->name('reports.csv');
    });
});
