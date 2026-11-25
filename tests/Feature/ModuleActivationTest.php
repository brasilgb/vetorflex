<?php

use App\Models\User;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\ModuleManager\Services\ModuleActivationService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

test('a module cannot be activated before its dependencies', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $products = PlatformModule::create(['identifier' => 'products', 'name' => 'Produtos', 'description' => 'Produtos']);
    $purchases = PlatformModule::create(['identifier' => 'purchases', 'name' => 'Compras', 'description' => 'Compras', 'dependencies' => ['products']]);

    expect(fn () => app(ModuleActivationService::class)->activate($company, $purchases, $user))
        ->toThrow(ValidationException::class);

    expect(CompanyModule::query()->count())->toBe(0);
});

test('an active dependency cannot be deactivated', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $products = PlatformModule::create(['identifier' => 'products', 'name' => 'Produtos', 'description' => 'Produtos']);
    $purchases = PlatformModule::create(['identifier' => 'purchases', 'name' => 'Compras', 'description' => 'Compras', 'dependencies' => ['products']]);
    $service = app(ModuleActivationService::class);

    $service->activate($company, $products, $user);
    $service->activate($company, $purchases, $user);

    expect(fn () => $service->deactivate($company, $products, $user))
        ->toThrow(ValidationException::class);
});
