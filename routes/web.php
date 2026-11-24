<?php

use App\Http\Controllers\RootAdmin\CompanyController;
use App\Http\Controllers\RootAdmin\ModuleCatalogController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::prefix('rootadmin')->name('root-admin.')->group(function (): void {
        Route::redirect('/', '/rootadmin/companies')->name('home');
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('modules', [ModuleCatalogController::class, 'index'])->name('modules.index');
    });
});

require __DIR__.'/settings.php';
