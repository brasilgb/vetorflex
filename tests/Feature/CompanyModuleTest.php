<?php

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\PlatformModuleSeeder;
use Inertia\Testing\AssertableInertia;

function rootAdmin(): User
{
    return User::factory()->create()->forceFill(['is_root_admin' => true]);
}

test('the root admin authorizes products without customers and the seed enables it for nobody', function () {
    $this->seed(PlatformModuleSeeder::class);
    $company = Company::factory()->create();
    $module = PlatformModule::query()->where('identifier', 'products')->sole();
    expect(CompanyModule::query()->count())->toBe(0);

    $this->actingAs(rootAdmin());
    $this->post("/rootadmin/companies/{$company->id}/modules/{$module->id}")->assertRedirect('/rootadmin/companies')->assertSessionHasNoErrors();

    $this->get('/rootadmin/companies')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('root-admin/companies')->where('companies.0.active_modules', [$module->id]));
    expect(AuditLog::query()->where('event', 'module.activated')->where('company_id', $company->id)->exists())->toBeTrue();
});

test('deactivating blocks access, preserves the data and allows a safe reactivation', function () {
    $company = Company::factory()->create();
    $owner = actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $module = PlatformModule::query()->where('identifier', 'products')->sole();
    $path = "/rootadmin/companies/{$company->id}/modules/{$module->id}";
    $rootAdmin = rootAdmin();

    $this->actingAs($rootAdmin)->delete($path)->assertSessionHasNoErrors();
    $this->actingAs($owner)->get("/products/{$product->id}")->assertForbidden();
    expect($product->fresh())->not->toBeNull();

    $this->actingAs($rootAdmin)->post($path)->assertSessionHasNoErrors();
    $this->actingAs($owner)->get("/products/{$product->id}")->assertOk();
    expect(CompanyModule::query()->count())->toBe(1);
});

test('only the root admin manages modules', function (string $method, string $path) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $module = PlatformModule::query()->where('identifier', 'products')->sole();

    $this->json($method, strtr($path, ['{company}' => $company->id, '{module}' => $module->id]), ['commercially_available' => true])->assertForbidden();
})->with([
    ['GET', '/rootadmin/companies'],
    ['POST', '/rootadmin/companies/{company}/modules/{module}'],
    ['DELETE', '/rootadmin/companies/{company}/modules/{module}'],
    ['PATCH', '/rootadmin/modules/{module}'],
]);

test('commercial availability requires an implemented module and survives a new seed', function () {
    $this->seed(PlatformModuleSeeder::class);
    $products = PlatformModule::query()->where('identifier', 'products')->sole();
    $finance = PlatformModule::query()->where('identifier', 'finance')->sole();
    expect($products)->development_status->toBe('implemented')->version->toBe('1.0.0')->commercially_available->toBeFalse();

    $this->actingAs(rootAdmin());
    $this->patch("/rootadmin/modules/{$products->id}", ['commercially_available' => true])->assertSessionHasNoErrors();
    $this->patch("/rootadmin/modules/{$finance->id}", ['commercially_available' => true])->assertSessionHasErrors('commercially_available');
    $this->seed(PlatformModuleSeeder::class);

    expect($products->refresh()->commercially_available)->toBeTrue();
    expect(CompanyModule::query()->count())->toBe(0);
});
