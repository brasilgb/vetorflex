<?php

use App\Models\User;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Autentica um membro da empresa com o módulo Produtos habilitado. Sem permissões informadas, é proprietário.
 *
 * @param  list<ProductPermission>|null  $permissions
 */
function actingAsProductsMember(Company $company, ?array $permissions = null): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, [
        'role' => $permissions === null ? 'owner' : 'operator',
        'is_active' => true,
        'permissions' => array_column($permissions ?? [], 'value'),
    ]);
    $module = PlatformModule::query()->firstOrCreate(['identifier' => 'products'], ['name' => 'Produtos', 'description' => 'Catálogo.', 'permissions' => ProductPermission::catalog()]);
    CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);
    test()->actingAs($user)->withSession(['active_company_id' => $company->id]);

    return $user;
}
