<?php

namespace App\Providers;

use App\Modules\Purchases\Contracts\PurchaseObligationSource;
use App\Modules\Purchases\Services\PurchaseObligations;
use App\Modules\Sales\Contracts\SaleReceivableSource;
use App\Modules\Sales\Services\SaleReceivables;
use App\Modules\Tenancy\Models\Company;
use App\Policies\Modules\Tenancy\Policies\CompanyPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
