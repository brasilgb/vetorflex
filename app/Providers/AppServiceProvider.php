<?php

namespace App\Providers;

use App\Modules\Finance\Listeners\SyncPurchaseObligations;
use App\Modules\Finance\Listeners\SyncSaleReceivables;
use App\Modules\Purchases\Contracts\PurchaseObligationSource;
use App\Modules\Purchases\Events\PurchaseBalanceClosed;
use App\Modules\Purchases\Events\PurchaseCancelled;
use App\Modules\Purchases\Events\PurchaseConfirmed;
use App\Modules\Purchases\Events\PurchaseReceiptReversed;
use App\Modules\Purchases\Events\PurchaseReturnRegistered;
use App\Modules\Purchases\Services\PurchaseObligations;
use App\Modules\Sales\Contracts\SaleReceivableSource;
use App\Modules\Sales\Events\SaleBalanceClosed;
use App\Modules\Sales\Events\SaleCancelled;
use App\Modules\Sales\Events\SaleConfirmed;
use App\Modules\Sales\Events\SaleFulfillmentReversed;
use App\Modules\Sales\Events\SaleReturnRegistered;
use App\Modules\Sales\Services\SaleReceivables;
use App\Modules\Tenancy\Models\Company;
use App\Policies\Modules\Tenancy\Policies\CompanyPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PurchaseObligationSource::class, PurchaseObligations::class);
        $this->app->bind(SaleReceivableSource::class, SaleReceivables::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::policy(Company::class, CompanyPolicy::class);
        $this->registerFinanceIntegrations();
    }

    /**
     * Integrações opcionais do Financeiro: os ouvintes ignoram empresas sem o módulo.
     */
    protected function registerFinanceIntegrations(): void
    {
        Event::listen([SaleConfirmed::class, SaleCancelled::class, SaleReturnRegistered::class, SaleBalanceClosed::class, SaleFulfillmentReversed::class], SyncSaleReceivables::class);
        Event::listen([PurchaseConfirmed::class, PurchaseCancelled::class, PurchaseReturnRegistered::class, PurchaseBalanceClosed::class, PurchaseReceiptReversed::class], SyncPurchaseObligations::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
