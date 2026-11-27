<?php

use App\Http\Controllers\Inventory\Stock\InventoryCountController;
use App\Http\Controllers\Inventory\Stock\StockDashboardController;
use App\Http\Controllers\Inventory\Stock\StockItemController;
use App\Http\Controllers\Inventory\Stock\StockMovementController;
use App\Http\Controllers\Inventory\Stock\StockReportController;
use App\Http\Controllers\Inventory\Stock\StockSettingsController;
use App\Http\Controllers\Inventory\Stock\StockTransferController;
use App\Http\Controllers\Inventory\Stock\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
| Rotas do módulo Estoque (`inventory`). Toda rota exige o módulo habilitado e `inventory.view`; as operações exigem,
| além disso, a permissão individual. A empresa vem sempre do contexto do usuário, nunca da requisição.
*/
Route::prefix('inventory')->name('inventory.')->middleware(['tenant', 'tenant.records', 'module.permission:inventory,inventory.view'])->group(function (): void {
    $permission = fn (string $ability): string => "module.permission:inventory,inventory.{$ability}";

    Route::get('/', StockDashboardController::class)->name('dashboard');

    Route::get('items', [StockItemController::class, 'index'])->name('items.index');
    Route::get('items/lookup', [StockItemController::class, 'lookup'])->name('items.lookup');
    Route::get('items/export', [StockItemController::class, 'export'])->middleware($permission('import_export'))->name('items.export');
    Route::get('items/template', [StockItemController::class, 'template'])->middleware($permission('import_export'))->name('items.template');
    Route::post('items/import', [StockItemController::class, 'import'])->middleware([$permission('import_export'), $permission('manage_items')])->name('items.import');
    Route::post('items', [StockItemController::class, 'store'])->middleware($permission('manage_items'))->name('items.store');
    Route::get('items/{variant}', [StockItemController::class, 'show'])->name('items.show');
    Route::put('items/{variant}/thresholds', [StockItemController::class, 'thresholds'])->middleware($permission('manage_items'))->name('items.thresholds');

    Route::get('movements', [StockMovementController::class, 'index'])->name('movements.index');
    Route::post('movements', [StockMovementController::class, 'store'])->name('movements.store');
    Route::post('movements/{movement}/reverse', [StockMovementController::class, 'reverse'])->middleware($permission('adjust'))->name('movements.reverse');

    Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
    Route::post('transfers', [StockTransferController::class, 'store'])->middleware($permission('transfer'))->name('transfers.store');

    Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::middleware($permission('manage_warehouses'))->group(function (): void {
        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    });

    Route::get('counts', [InventoryCountController::class, 'index'])->name('counts.index');
    Route::get('counts/{count}', [InventoryCountController::class, 'show'])->name('counts.show');
    Route::middleware($permission('count'))->group(function (): void {
        Route::post('counts', [InventoryCountController::class, 'store'])->name('counts.store');
        Route::put('counts/{count}', [InventoryCountController::class, 'update'])->name('counts.update');
        Route::post('counts/{count}/cancel', [InventoryCountController::class, 'cancel'])->name('counts.cancel');
    });
    Route::post('counts/{count}/approve', [InventoryCountController::class, 'approve'])->middleware($permission('approve_counts'))->name('counts.approve');

    Route::middleware($permission('view_reports'))->group(function (): void {
        Route::get('reports', [StockReportController::class, 'index'])->name('reports.index');
        Route::get('reports/csv', [StockReportController::class, 'csv'])->name('reports.csv');
    });

    Route::middleware($permission('manage_settings'))->group(function (): void {
        Route::get('settings', [StockSettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [StockSettingsController::class, 'update'])->name('settings.update');
    });
});
