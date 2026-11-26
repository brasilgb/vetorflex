<?php

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia;

/** @param list<string> $permissions */
function companyMember(Company $company, string $role, array $permissions = []): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => $role, 'is_active' => true, 'permissions' => $permissions]);

    return $user;
}

/** @return list<string> */
function permissionsOf(Company $company, User $user): array
{
    return $user->companies()->whereKey($company->id)->first()->pivot->permissions ?? [];
}

test('owner grants and revokes permissions of enabled modules with history', function () {
    $company = Company::factory()->create();
    $owner = actingAsPurchasesMember($company);
    $operator = companyMember($company, 'operator', ['purchases.view', 'legacy.permission']);

    $this->put("/company/members/{$operator->id}/permissions", ['permissions' => ['purchases.create', 'products.view']])->assertSessionHasNoErrors();

    expect(permissionsOf($company, $operator))->toBe(['legacy.permission', 'products.view', 'purchases.create']);
    expect(AuditLog::query()->where('event', 'company.permissions_changed')->sole())
        ->user_id->toBe($owner->id)
        ->context->toMatchArray(['granted' => ['products.view', 'purchases.create'], 'revoked' => ['purchases.view']]);

    $this->get('/company/members')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('company/members')->has('history', 1)->has('modules', 2));
});

test('administrators cannot grant what they do not have nor edit owners and administrators', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $owner = User::query()->firstOrFail();
    $administrator = companyMember($company, 'administrator', ['purchases.view', 'purchases.create']);
    $otherAdministrator = companyMember($company, 'administrator');
    $operator = companyMember($company, 'operator', ['purchases.view_cost']);
    $this->actingAs($administrator)->withSession(['active_company_id' => $company->id]);

    $this->put("/company/members/{$operator->id}/permissions", ['permissions' => ['purchases.view_cost']])->assertSessionHasErrors('permissions');
    $this->put("/company/members/{$operator->id}/permissions", ['permissions' => ['purchases.create']])->assertSessionHasNoErrors();
    $this->put("/company/members/{$otherAdministrator->id}/permissions", ['permissions' => ['purchases.view']])->assertSessionHasErrors('permissions');
    $this->put("/company/members/{$owner->id}/permissions", ['permissions' => []])->assertSessionHasErrors('permissions');
    $this->put("/company/members/{$administrator->id}/permissions", ['permissions' => ['purchases.view']])->assertSessionHasErrors('permissions');

    expect(permissionsOf($company, $operator))->toBe(['purchases.create', 'purchases.view_cost']);
    expect(permissionsOf($company, $otherAdministrator))->toBe([]);
});

test('operators cannot manage permissions and other companies are isolated', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company, [PurchasePermission::View, ProductPermission::View]);
    $this->get('/company/members')->assertForbidden();

    $other = Company::factory()->create();
    $outsider = companyMember($other, 'operator');
    actingAsPurchasesMember($company);
    $this->put("/company/members/{$outsider->id}/permissions", ['permissions' => ['purchases.view']])->assertSessionHasErrors('permissions');
    expect(permissionsOf($other, $outsider))->toBe([]);
});

test('root admin flag cannot be changed from the company panel', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $operator = companyMember($company, 'operator');

    $this->put("/company/members/{$operator->id}/permissions", ['permissions' => ['purchases.view'], 'is_root_admin' => true, 'role' => 'owner'])->assertSessionHasNoErrors();

    expect($operator->fresh()->is_root_admin)->toBeFalse();
    expect($operator->companies()->first()->pivot->role->value)->toBe('operator');
});
