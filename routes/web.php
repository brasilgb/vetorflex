<?php

use App\Http\Controllers\Company\CompanyMemberController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Payments\PaymentWebhookController;
use App\Http\Controllers\RootAdmin\AdministratorController;
use App\Http\Controllers\RootAdmin\AuditLogController;
use App\Http\Controllers\RootAdmin\CompanyController;
use App\Http\Controllers\RootAdmin\CompanyModuleController;
use App\Http\Controllers\RootAdmin\ModuleCatalogController;
use App\Http\Controllers\RootAdmin\PaymentsAdminController;
use App\Http\Controllers\RootAdmin\PlatformOverviewController;
use App\Http\Controllers\RootAdmin\PlatformSettingsController;
use App\Http\Controllers\RootAdmin\PlatformUserController;
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
    require __DIR__.'/sales.php';
    require __DIR__.'/finance.php';
    require __DIR__.'/pos.php';
    require __DIR__.'/inventory.php';
    require __DIR__.'/payments.php';

    Route::middleware('tenant')->group(function (): void {
        Route::get('company/members', [CompanyMemberController::class, 'index'])->name('company.members.index');
        Route::put('company/members/{member}/permissions', [CompanyMemberController::class, 'update'])->name('company.members.permissions.update');
    });

    Route::prefix('rootadmin')->name('root-admin.')->middleware('root.admin')->group(function (): void {
        Route::get('/', PlatformOverviewController::class)->name('home');
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::put('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::patch('companies/{company}/status', [CompanyController::class, 'status'])->name('companies.status');
        Route::get('users', [PlatformUserController::class, 'index'])->name('users.index');
        Route::get('administrators', [AdministratorController::class, 'index'])->name('administrators.index');
        Route::delete('administrators/{administrator}', [AdministratorController::class, 'destroy'])->name('administrators.destroy');
        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('settings', PlatformSettingsController::class)->name('settings');
        Route::post('companies/{company}/modules/{module}', [CompanyModuleController::class, 'store'])->withoutScopedBindings()->name('companies.modules.store');
        Route::delete('companies/{company}/modules/{module}', [CompanyModuleController::class, 'destroy'])->withoutScopedBindings()->name('companies.modules.destroy');
        Route::get('modules', [ModuleCatalogController::class, 'index'])->name('modules.index');
        Route::patch('modules/{module}', [ModuleCatalogController::class, 'update'])->name('modules.update');
        Route::get('payments', [PaymentsAdminController::class, 'index'])->name('payments.index');
        Route::patch('payments/providers/{provider}', [PaymentsAdminController::class, 'update'])->name('payments.providers.update');
    });
});

require __DIR__.'/settings.php';

// Notificações de provedores de pagamento (sem sessão, sem CSRF). O sufixo opcional atende o "/pix" da Efí.
Route::post('webhooks/payments/{provider}/{token}/{suffix?}', PaymentWebhookController::class)
    ->where(['provider' => '[a-z]+', 'token' => '[A-Za-z0-9]{48}', 'suffix' => 'pix'])
    ->middleware('throttle:120,1')
    ->name('payments.webhook');
