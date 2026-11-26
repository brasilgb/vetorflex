<?php

use App\Http\Controllers\Purchases\PurchaseDashboardController;
use App\Http\Controllers\Purchases\PurchaseOrderController;
use App\Http\Controllers\Purchases\PurchaseOrderDocumentController;
use App\Http\Controllers\Purchases\PurchaseReceiptController;
use App\Http\Controllers\Purchases\PurchaseReturnController;
use App\Http\Controllers\Purchases\QuickPurchaseController;
use App\Http\Controllers\Purchases\SupplierController;
use App\Http\Controllers\Purchases\SupplierImportController;
use App\Http\Controllers\Purchases\SupplierProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('purchases')->name('purchases.')->middleware(['tenant', 'tenant.records', 'module.permission:purchases,purchases.view'])->group(function (): void {
    Route::get('/', PurchaseDashboardController::class)->name('dashboard');

    Route::get('orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/export', [PurchaseOrderController::class, 'export'])->middleware('module.permission:purchases,purchases.export')->name('orders.export');
    Route::get('items/lookup', [PurchaseOrderController::class, 'lookup'])->name('items.lookup');
    Route::middleware('module.permission:purchases,purchases.create')->group(function (): void {
        Route::get('orders/create', [PurchaseOrderController::class, 'create'])->name('orders.create');
        Route::post('orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
    });
    Route::middleware(['module.permission:purchases,purchases.create', 'module.permission:purchases,purchases.confirm', 'module.permission:purchases,purchases.receive', 'module.permission:purchases,purchases.manage_cost'])->group(function (): void {
        Route::get('quick', [QuickPurchaseController::class, 'create'])->name('quick.create');
        Route::post('quick', [QuickPurchaseController::class, 'store'])->name('quick.store');
    });
    Route::get('orders/{order}', [PurchaseOrderController::class, 'show'])->name('orders.show');
    Route::get('orders/{order}/document', PurchaseOrderDocumentController::class)->name('orders.document');
    Route::middleware('module.permission:purchases,purchases.update')->group(function (): void {
        Route::get('orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->name('orders.edit');
        Route::put('orders/{order}', [PurchaseOrderController::class, 'update'])->name('orders.update');
    });
    Route::post('orders/{order}/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('module.permission:purchases,purchases.confirm')->name('orders.confirm');
    Route::middleware('module.permission:purchases,purchases.cancel')->group(function (): void {
        Route::post('orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{order}/close-balance', [PurchaseOrderController::class, 'closeBalance'])->name('orders.close-balance');
    });

    Route::get('receipts', [PurchaseReceiptController::class, 'index'])->name('receipts.index');
    Route::post('orders/{order}/receipts', [PurchaseReceiptController::class, 'store'])->middleware('module.permission:purchases,purchases.receive')->name('receipts.store');
    Route::middleware('module.permission:purchases,purchases.return')->group(function (): void {
        Route::post('receipts/{receipt}/reverse', [PurchaseReceiptController::class, 'reverse'])->name('receipts.reverse');
        Route::post('receipts/{receipt}/returns', [PurchaseReturnController::class, 'store'])->name('returns.store');
    });
    Route::get('returns', [PurchaseReturnController::class, 'index'])->name('returns.index');

    Route::prefix('suppliers')->name('suppliers.')->group(function (): void {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::get('lookup', [SupplierController::class, 'lookup'])->name('lookup');
        Route::get('export', [SupplierController::class, 'export'])->middleware('module.permission:purchases,purchases.export_suppliers')->name('export');
        Route::middleware('module.permission:purchases,purchases.import_suppliers')->group(function (): void {
            Route::get('imports/create', [SupplierImportController::class, 'create'])->name('imports.create');
            Route::get('imports/template', [SupplierImportController::class, 'template'])->name('imports.template');
            Route::post('imports', [SupplierImportController::class, 'store'])->name('imports.store');
            Route::get('imports/{import}', [SupplierImportController::class, 'show'])->name('imports.show');
            Route::post('imports/{import}/confirm', [SupplierImportController::class, 'confirm'])->name('imports.confirm');
            Route::get('imports/{import}/errors', [SupplierImportController::class, 'errors'])->name('imports.errors');
        });
        Route::middleware('module.permission:purchases,purchases.manage_suppliers')->group(function (): void {
            Route::get('create', [SupplierController::class, 'create'])->name('create');
            Route::post('/', [SupplierController::class, 'store'])->name('store');
            Route::get('{supplier}/edit', [SupplierController::class, 'edit'])->name('edit');
            Route::put('{supplier}', [SupplierController::class, 'update'])->name('update');
            Route::patch('{supplier}/deactivate', [SupplierController::class, 'deactivate'])->name('deactivate');
            Route::patch('{supplier}/reactivate', [SupplierController::class, 'reactivate'])->name('reactivate');
            Route::post('{supplier}/products', [SupplierProductController::class, 'store'])->name('products.store');
            Route::put('{supplier}/products/{link}', [SupplierProductController::class, 'update'])->name('products.update');
            Route::delete('{supplier}/products/{link}', [SupplierProductController::class, 'destroy'])->name('products.destroy');
        });
        Route::get('{supplier}', [SupplierController::class, 'show'])->name('show');
    });
});
