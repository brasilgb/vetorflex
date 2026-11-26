<?php

use App\Http\Controllers\Company\CompanyMemberController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\RootAdmin\CompanyController;
use App\Http\Controllers\RootAdmin\CompanyModuleController;
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

    require __DIR__.'/products.php';
    require __DIR__.'/purchases.php';

    Route::middleware('tenant')->group(function (): void {
        Route::get('company/members', [CompanyMemberController::class, 'index'])->name('company.members.index');
        Route::put('company/members/{member}/permissions', [CompanyMemberController::class, 'update'])->name('company.members.permissions.update');
    });

    Route::prefix('rootadmin')->name('root-admin.')->group(function (): void {
        Route::redirect('/', '/rootadmin/companies')->name('home');
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::post('companies/{company}/modules/{module}', [CompanyModuleController::class, 'store'])->withoutScopedBindings()->name('companies.modules.store');
        Route::delete('companies/{company}/modules/{module}', [CompanyModuleController::class, 'destroy'])->withoutScopedBindings()->name('companies.modules.destroy');
        Route::get('modules', [ModuleCatalogController::class, 'index'])->name('modules.index');
        Route::patch('modules/{module}', [ModuleCatalogController::class, 'update'])->name('modules.update');
    });
});

require __DIR__.'/settings.php';
