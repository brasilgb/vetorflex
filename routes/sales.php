<?php

use App\Http\Controllers\Sales\SaleCustomerController;
use App\Http\Controllers\Sales\SaleDocumentController;
use App\Http\Controllers\Sales\SaleFulfillmentController;
use App\Http\Controllers\Sales\SaleItemController;
use App\Http\Controllers\Sales\SaleOrderController;
use App\Http\Controllers\Sales\SaleQuoteController;
use App\Http\Controllers\Sales\SaleReturnController;
use App\Http\Controllers\Sales\SalesDashboardController;
use App\Http\Controllers\Sales\SalesSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('sales')->name('sales.')->middleware(['tenant', 'tenant.records', 'module.permission:sales,sales.view'])->group(function (): void {
    Route::get('/', SalesDashboardController::class)->name('dashboard');
    Route::middleware('module.permission:sales,sales.manage_settings')->group(function (): void {
        Route::get('settings', [SalesSettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [SalesSettingsController::class, 'update'])->name('settings.update');
    });

    Route::get('customers/lookup', [SaleCustomerController::class, 'lookup'])->name('customers.lookup');
    Route::post('customers', [SaleCustomerController::class, 'store'])->middleware('module.permission:sales,sales.register_customers')->name('customers.store');
    Route::get('items/lookup', [SaleItemController::class, 'lookup'])->name('items.lookup');
    Route::post('items', [SaleItemController::class, 'store'])->middleware('module.permission:sales,sales.register_items')->name('items.store');

    Route::get('quotes', [SaleQuoteController::class, 'index'])->name('quotes.index');
    Route::middleware('module.permission:sales,sales.manage_quotes')->group(function (): void {
        Route::get('quotes/create', [SaleQuoteController::class, 'create'])->name('quotes.create');
        Route::post('quotes', [SaleQuoteController::class, 'store'])->name('quotes.store');
        Route::get('quotes/{quote}/edit', [SaleQuoteController::class, 'edit'])->name('quotes.edit');
        Route::put('quotes/{quote}', [SaleQuoteController::class, 'update'])->name('quotes.update');
        Route::post('quotes/{quote}/issue', [SaleQuoteController::class, 'issue'])->name('quotes.issue');
        Route::post('quotes/{quote}/cancel', [SaleQuoteController::class, 'cancel'])->name('quotes.cancel');
        Route::post('quotes/{quote}/convert', [SaleQuoteController::class, 'convert'])->middleware('module.permission:sales,sales.create')->name('quotes.convert');
    });
    Route::middleware('module.permission:sales,sales.approve_quotes')->group(function (): void {
        Route::post('quotes/{quote}/approve', [SaleQuoteController::class, 'approve'])->name('quotes.approve');
        Route::post('quotes/{quote}/reject', [SaleQuoteController::class, 'reject'])->name('quotes.reject');
    });
    Route::get('quotes/{quote}', [SaleQuoteController::class, 'show'])->name('quotes.show');
    Route::get('quotes/{quote}/document', [SaleDocumentController::class, 'quote'])->name('quotes.document');

    Route::get('orders', [SaleOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/export', [SaleOrderController::class, 'export'])->middleware('module.permission:sales,sales.export')->name('orders.export');
    Route::middleware('module.permission:sales,sales.create')->group(function (): void {
        Route::get('orders/create', [SaleOrderController::class, 'create'])->name('orders.create');
        Route::post('orders', [SaleOrderController::class, 'store'])->name('orders.store');
    });
    Route::get('orders/{order}', [SaleOrderController::class, 'show'])->name('orders.show');
    Route::get('orders/{order}/document', [SaleDocumentController::class, 'order'])->name('orders.document');
    Route::middleware('module.permission:sales,sales.update')->group(function (): void {
        Route::get('orders/{order}/edit', [SaleOrderController::class, 'edit'])->name('orders.edit');
        Route::put('orders/{order}', [SaleOrderController::class, 'update'])->name('orders.update');
    });
    Route::middleware('module.permission:sales,sales.confirm')->group(function (): void {
        Route::post('orders/{order}/confirm', [SaleOrderController::class, 'confirm'])->name('orders.confirm');
        Route::post('orders/{order}/complete', [SaleOrderController::class, 'complete'])->name('orders.complete');
    });
    Route::middleware('module.permission:sales,sales.cancel')->group(function (): void {
        Route::post('orders/{order}/cancel', [SaleOrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{order}/close-balance', [SaleOrderController::class, 'closeBalance'])->name('orders.close-balance');
    });

    Route::get('fulfillments', [SaleFulfillmentController::class, 'index'])->name('fulfillments.index');
    Route::get('fulfillments/{fulfillment}/document', [SaleDocumentController::class, 'fulfillment'])->name('fulfillments.document');
    Route::middleware('module.permission:sales,sales.fulfill')->group(function (): void {
        Route::post('orders/{order}/fulfillments', [SaleFulfillmentController::class, 'store'])->name('fulfillments.store');
        Route::post('fulfillments/{fulfillment}/reverse', [SaleFulfillmentController::class, 'reverse'])->name('fulfillments.reverse');
    });

    Route::get('returns', [SaleReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/{return}/document', [SaleDocumentController::class, 'saleReturn'])->name('returns.document');
    Route::post('fulfillments/{fulfillment}/returns', [SaleReturnController::class, 'store'])->middleware('module.permission:sales,sales.return')->name('returns.store');
});
