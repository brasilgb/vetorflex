<?php

use App\Http\Controllers\Company\CompanyMemberController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Fiscal\FiscalWebhookController;
use App\Http\Controllers\Payments\PaymentWebhookController;
use App\Http\Controllers\Public\LandingController;
use App\Http\Controllers\Public\SignupController;
use App\Http\Controllers\RootAdmin\AdministratorController;
use App\Http\Controllers\RootAdmin\AuditLogController;
use App\Http\Controllers\RootAdmin\CompanyController;
use App\Http\Controllers\RootAdmin\CompanyModuleController;
use App\Http\Controllers\RootAdmin\ContactRequestController;
use App\Http\Controllers\RootAdmin\FiscalAdminController;
use App\Http\Controllers\RootAdmin\ModuleCatalogController;
use App\Http\Controllers\RootAdmin\PaymentsAdminController;
use App\Http\Controllers\RootAdmin\PlatformOverviewController;
use App\Http\Controllers\RootAdmin\PlatformSettingsController;
use App\Http\Controllers\RootAdmin\PlatformUserController;
use App\Http\Controllers\Tenant\ActiveCompanyController;
use App\Http\Controllers\Tenant\CompanyApplicationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('home');
Route::get('privacidade', [LandingController::class, 'privacy'])->name('legal.privacy');
Route::get('termos', [LandingController::class, 'terms'])->name('legal.terms');
Route::post('contato', [LandingController::class, 'contact'])->middleware('throttle:contact')->name('contact.store');

// Cadastro público (jornada comercial): a empresa é provisionada após a verificação do e-mail.
Route::get('cadastro', [SignupController::class, 'create'])->name('register');
Route::post('cadastro', [SignupController::class, 'store'])->middleware(['guest', 'throttle:signup'])->name('register.store');
Route::post('cadastro/revisao', [SignupController::class, 'quote'])->middleware('throttle:60,1')->name('register.quote');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
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
    require __DIR__.'/fiscal.php';

    Route::middleware('tenant')->group(function (): void {
        Route::get('company/members', [CompanyMemberController::class, 'index'])->name('company.members.index');
        Route::get('company/applications', [CompanyApplicationController::class, 'index'])->name('company.applications.index');
        Route::post('company/applications', [CompanyApplicationController::class, 'store'])->name('company.applications.store');
        Route::delete('company/applications/{module}', [CompanyApplicationController::class, 'destroy'])->where('module', '[a-z_]+')->name('company.applications.destroy');
        Route::put('company/members/{member}/permissions', [CompanyMemberController::class, 'update'])->name('company.members.permissions.update');
    });

    Route::prefix('rootadmin')->name('root-admin.')->middleware('root.admin')->group(function (): void {
        Route::get('/', PlatformOverviewController::class)->name('home');
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::put('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::patch('companies/{company}/status', [CompanyController::class, 'status'])->name('companies.status');
        Route::patch('companies/{company}/trial', [CompanyController::class, 'trial'])->name('companies.trial');
        Route::patch('companies/{company}/contract', [CompanyController::class, 'contract'])->name('companies.contract');
        Route::get('users', [PlatformUserController::class, 'index'])->name('users.index');
        Route::get('administrators', [AdministratorController::class, 'index'])->name('administrators.index');
        Route::delete('administrators/{administrator}', [AdministratorController::class, 'destroy'])->name('administrators.destroy');
        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('settings', PlatformSettingsController::class)->name('settings');
        Route::post('companies/{company}/modules/{module}', [CompanyModuleController::class, 'store'])->withoutScopedBindings()->name('companies.modules.store');
        Route::delete('companies/{company}/modules/{module}', [CompanyModuleController::class, 'destroy'])->withoutScopedBindings()->name('companies.modules.destroy');
        Route::get('modules', [ModuleCatalogController::class, 'index'])->name('modules.index');
        Route::patch('modules/{module}', [ModuleCatalogController::class, 'update'])->name('modules.update');
        Route::put('modules/{module}/commercial', [ModuleCatalogController::class, 'commercial'])->name('modules.commercial');
        Route::get('contacts', [ContactRequestController::class, 'index'])->name('contacts.index');
        Route::patch('contacts/{contact}', [ContactRequestController::class, 'update'])->name('contacts.update');
        Route::get('payments', [PaymentsAdminController::class, 'index'])->name('payments.index');
        Route::patch('payments/providers/{provider}', [PaymentsAdminController::class, 'update'])->name('payments.providers.update');
        Route::get('fiscal', [FiscalAdminController::class, 'index'])->name('fiscal.index');
        Route::patch('fiscal/providers/{provider}', [FiscalAdminController::class, 'update'])->name('fiscal.providers.update');
        Route::post('fiscal/providers/{provider}/test', [FiscalAdminController::class, 'test'])->name('fiscal.providers.test');
        Route::post('fiscal/providers/{provider}/webhook', [FiscalAdminController::class, 'webhook'])->name('fiscal.providers.webhook');
        Route::patch('fiscal/companies/{setting}/release', [FiscalAdminController::class, 'release'])->name('fiscal.companies.release');
    });
});

require __DIR__.'/settings.php';

// Notificações de provedores de pagamento (sem sessão, sem CSRF). O sufixo opcional atende o "/pix" da Efí.
Route::post('webhooks/payments/{provider}/{token}/{suffix?}', PaymentWebhookController::class)
    ->where(['provider' => '[a-z]+', 'token' => '[A-Za-z0-9]{48}', 'suffix' => 'pix'])
    ->middleware('throttle:120,1')
    ->name('payments.webhook');

// Notificações de provedores fiscais (conta da plataforma; sem sessão, sem CSRF), autenticadas pela assinatura.
Route::post('webhooks/fiscal/{provider}', FiscalWebhookController::class)
    ->where(['provider' => '[a-z]+'])
    ->middleware('throttle:240,1')
    ->name('fiscal.webhook');
