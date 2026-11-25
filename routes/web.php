<?php

use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\RootAdmin\CompanyController;
use App\Http\Controllers\RootAdmin\ModuleCatalogController;
use App\Http\Controllers\Tenant\ActiveCompanyController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::post('active-company', ActiveCompanyController::class)->name('active-company.update');

    Route::middleware(['tenant', 'module.permission:customers,customers.view'])->group(function (): void {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/export', [CustomerController::class, 'export'])->middleware('module.permission:customers,customers.export')->name('customers.export');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    });
    Route::post('customers', [CustomerController::class, 'store'])->middleware(['tenant', 'module.permission:customers,customers.create'])->name('customers.store');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware(['tenant', 'module.permission:customers,customers.update'])->name('customers.update');
    Route::patch('customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->middleware(['tenant', 'module.permission:customers,customers.deactivate'])->name('customers.deactivate');
    Route::patch('customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->middleware(['tenant', 'module.permission:customers,customers.reactivate'])->name('customers.reactivate');
    Route::post('customers/import', [CustomerController::class, 'import'])->middleware(['tenant', 'module.permission:customers,customers.import'])->name('customers.import');

    Route::prefix('rootadmin')->name('root-admin.')->group(function (): void {
        Route::redirect('/', '/rootadmin/companies')->name('home');
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('modules', [ModuleCatalogController::class, 'index'])->name('modules.index');
    });
});

require __DIR__.'/settings.php';
