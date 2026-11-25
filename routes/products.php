<?php

use App\Http\Controllers\Inventory\InventoryMovementController;
use App\Http\Controllers\Products\ProductBrandController;
use App\Http\Controllers\Products\ProductCategoryController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Products\ProductExportController;
use App\Http\Controllers\Products\ProductImageController;
use App\Http\Controllers\Products\ProductImportController;
use App\Http\Controllers\Products\ProductVariantController;
use App\Http\Controllers\Products\UnitOfMeasureController;
use Illuminate\Support\Facades\Route;

Route::prefix('products')->name('products.')->middleware(['tenant', 'tenant.records', 'module.permission:products,products.view'])->scopeBindings()->group(function (): void {
    Route::get('/', [ProductController::class, 'index'])->name('index');
    Route::get('lookup', [ProductController::class, 'lookup'])->name('lookup');

    Route::middleware('module.permission:products,products.create')->group(function (): void {
        Route::get('create', [ProductController::class, 'create'])->name('create');
        Route::post('/', [ProductController::class, 'store'])->name('store');
    });

    Route::get('categories', [ProductCategoryController::class, 'index'])->name('categories.index');
    Route::get('brands', [ProductBrandController::class, 'index'])->name('brands.index');
    Route::get('units', [UnitOfMeasureController::class, 'index'])->name('units.index');

    Route::middleware('module.permission:products,products.manage_categories')->group(function (): void {
        Route::post('categories', [ProductCategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [ProductCategoryController::class, 'update'])->name('categories.update');
    });
    Route::middleware('module.permission:products,products.manage_brands')->group(function (): void {
        Route::post('brands', [ProductBrandController::class, 'store'])->name('brands.store');
        Route::put('brands/{brand}', [ProductBrandController::class, 'update'])->name('brands.update');
    });
    Route::middleware('module.permission:products,products.manage_units')->group(function (): void {
        Route::post('units', [UnitOfMeasureController::class, 'store'])->name('units.store');
        Route::put('units/{unit}', [UnitOfMeasureController::class, 'update'])->name('units.update');
    });

    Route::get('export', ProductExportController::class)->middleware('module.permission:products,products.export')->name('export');
    Route::middleware('module.permission:products,products.import')->group(function (): void {
        Route::get('imports/create', [ProductImportController::class, 'create'])->name('imports.create');
        Route::get('imports/template', [ProductImportController::class, 'template'])->name('imports.template');
        Route::post('imports', [ProductImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{import}', [ProductImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{import}/confirm', [ProductImportController::class, 'confirm'])->name('imports.confirm');
        Route::get('imports/{import}/errors', [ProductImportController::class, 'errors'])->name('imports.errors');
    });

    Route::get('movements', [InventoryMovementController::class, 'index'])->middleware('module.permission:products,products.view_inventory')->name('movements.index');
    Route::post('movements', [InventoryMovementController::class, 'store'])->middleware('module.permission:products,products.adjust_inventory')->name('movements.store');
    Route::post('movements/{movement}/reverse', [InventoryMovementController::class, 'reverse'])->middleware('module.permission:products,products.adjust_inventory')->name('movements.reverse');

    Route::get('{product}', [ProductController::class, 'show'])->name('show');
    Route::patch('{product}/deactivate', [ProductController::class, 'deactivate'])->middleware('module.permission:products,products.deactivate')->name('deactivate');
    Route::patch('{product}/reactivate', [ProductController::class, 'reactivate'])->middleware('module.permission:products,products.reactivate')->name('reactivate');

    Route::middleware('module.permission:products,products.update')->group(function (): void {
        Route::get('{product}/edit', [ProductController::class, 'edit'])->name('edit');
        Route::put('{product}', [ProductController::class, 'update'])->name('update');
        Route::post('{product}/variants/preview', [ProductVariantController::class, 'preview'])->name('variants.preview');
        Route::post('{product}/variants', [ProductVariantController::class, 'store'])->name('variants.store');
        Route::put('{product}/variants/{variant}', [ProductVariantController::class, 'update'])->name('variants.update');
    });

    Route::get('{product}/images/{image}', [ProductImageController::class, 'show'])->name('images.show');
    Route::middleware('module.permission:products,products.update')->group(function (): void {
        Route::post('{product}/images', [ProductImageController::class, 'store'])->name('images.store');
        Route::patch('{product}/images/{image}', [ProductImageController::class, 'update'])->name('images.update');
        Route::delete('{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('images.destroy');
    });
});
