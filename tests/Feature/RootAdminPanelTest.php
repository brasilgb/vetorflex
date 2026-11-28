<?php

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => $this->withoutVite());

function platformAdmin(array $attributes = []): User
{
    return User::factory()->create(['is_root_admin' => true, ...$attributes]);
}

test('rootadmin:create registers an administrator with a hashed password', function () {
    $this->artisan('rootadmin:create')
        ->expectsQuestion('Nome', 'Dona da Plataforma')
        ->expectsQuestion('E-mail', 'Admin@Plataforma.test')
        ->expectsQuestion('Senha (mínimo de 8 caracteres; pode ser só números)', 'Segura#2026abc')
        ->expectsQuestion('Confirme a senha', 'Segura#2026abc')
        ->assertSuccessful();

    $user = User::query()->where('email', 'admin@plataforma.test')->sole();
    expect($user)->is_root_admin->toBeTrue()->email_verified_at->not->toBeNull();
    expect($user->password)->not->toBe('Segura#2026abc');
    expect(Hash::check('Segura#2026abc', $user->password))->toBeTrue();
    expect(AuditLog::query()->where('event', 'root_admin.created')->exists())->toBeTrue();
});

test('rootadmin:create refuses an existing e-mail, passwords under 8 characters and a wrong confirmation', function (string $email, string $password, string $confirmation) {
    $existing = User::factory()->create(['email' => 'comum@empresa.test', 'is_root_admin' => false]);

    $this->artisan('rootadmin:create', ['--name' => 'Teste', '--email' => $email])
        ->expectsQuestion('Senha (mínimo de 8 caracteres; pode ser só números)', $password)
        ->expectsQuestion('Confirme a senha', $confirmation)
        ->assertFailed();

    expect($existing->refresh()->is_root_admin)->toBeFalse();
    expect(User::query()->where('is_root_admin', true)->count())->toBe(0);
})->with([
    'existing e-mail' => ['comum@empresa.test', 'Segura#2026abc', 'Segura#2026abc'],
    'password under 8 characters' => ['novo@plataforma.test', '1234567', '1234567'],
    'confirmation mismatch' => ['novo@plataforma.test', 'Segura#2026abc', 'Segura#2026abd'],
]);

test('login redirects the RootAdmin to /rootadmin and company users to /dashboard', function () {
    $admin = platformAdmin(['password' => 'Segura#2026abc']);
    $this->post('/login', ['email' => $admin->email, 'password' => 'Segura#2026abc'])->assertRedirect('/rootadmin');
    $this->post('/logout');

    $member = User::factory()->create(['password' => 'Segura#2026abc']);
    $this->post('/login', ['email' => $member->email, 'password' => 'Segura#2026abc'])->assertRedirect('/dashboard');
});

test('company users cannot reach any administrative route', function (string $method, string $url) {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);

    $this->actingAs($user)->{$method}($url)->assertForbidden();
})->with([
    ['get', '/rootadmin'],
    ['get', '/rootadmin/companies'],
    ['get', '/rootadmin/users'],
    ['get', '/rootadmin/administrators'],
    ['get', '/rootadmin/audit'],
    ['get', '/rootadmin/settings'],
    ['get', '/rootadmin/modules'],
    ['post', '/rootadmin/companies'],
    ['delete', '/rootadmin/administrators/1'],
]);

test('the RootAdmin panel works without a company or modules', function (string $url, string $component) {
    platformAdmin();
    Company::factory()->create();

    $this->actingAs(User::query()->sole())->get($url)->assertOk()->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/rootadmin', 'root-admin/overview'],
    ['/rootadmin/companies', 'root-admin/companies'],
    ['/rootadmin/users', 'root-admin/users'],
    ['/rootadmin/administrators', 'root-admin/administrators'],
    ['/rootadmin/audit', 'root-admin/audit'],
    ['/rootadmin/settings', 'root-admin/settings'],
    ['/rootadmin/modules', 'root-admin/modules'],
]);

test('the overview reports real platform counts', function () {
    $admin = platformAdmin();
    Company::factory()->create(['status' => 'active']);
    Company::factory()->create(['status' => 'suspended']);
    User::factory()->create();

    $this->actingAs($admin)->get('/rootadmin')->assertInertia(fn ($page) => $page
        ->where('companies.active', 1)->where('companies.suspended', 1)
        ->where('users.total', 2)->where('users.root_admins', 1)->where('users.without_company', 1));
});

test('the RootAdmin creates, edits, suspends and reactivates a company with audit', function () {
    $admin = platformAdmin();
    $this->actingAs($admin);

    $this->post('/rootadmin/companies', ['name' => 'Padaria Central', 'slug' => 'padaria-central'])->assertSessionHasNoErrors();
    $company = Company::query()->where('slug', 'padaria-central')->sole();
    expect($company)->status->toBe('active')->timezone->toBe('America/Sao_Paulo');
    expect($company->moduleAssignments()->count())->toBe(0);

    $this->put("/rootadmin/companies/{$company->id}", ['name' => 'Padaria Central Ltda', 'slug' => 'padaria-central', 'timezone' => 'America/Manaus'])->assertSessionHasNoErrors();
    expect($company->refresh())->name->toBe('Padaria Central Ltda')->timezone->toBe('America/Manaus');

    $member = User::factory()->create();
    $member->companies()->attach($company, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
    $this->patch("/rootadmin/companies/{$company->id}/status", ['status' => 'suspended', 'reason' => ''])->assertSessionHasErrors('reason');
    $this->patch("/rootadmin/companies/{$company->id}/status", ['status' => 'suspended', 'reason' => 'Inadimplência'])->assertSessionHasNoErrors();
    expect($company->refresh()->status)->toBe('suspended');

    $this->actingAs($member)->withSession(['active_company_id' => $company->id])->get('/company/members')->assertNotFound();

    $this->actingAs($admin)->patch("/rootadmin/companies/{$company->id}/status", ['status' => 'active', 'reason' => 'Regularizada'])->assertSessionHasNoErrors();
    expect($company->refresh()->status)->toBe('active');
    expect(AuditLog::query()->where('company_id', $company->id)->pluck('event')->all())
        ->toContain('company.created', 'company.updated', 'company.suspended', 'company.reactivated');
});

test('the last active RootAdmin cannot be revoked', function () {
    $admin = platformAdmin();
    $this->actingAs($admin);

    $this->delete("/rootadmin/administrators/{$admin->id}", ['reason' => 'Teste'])->assertSessionHasErrors('administrator');
    expect($admin->refresh()->is_root_admin)->toBeTrue();

    $other = platformAdmin();
    $this->delete("/rootadmin/administrators/{$other->id}", ['reason' => 'Saiu da empresa'])->assertSessionHasNoErrors();
    expect($other->refresh()->is_root_admin)->toBeFalse();
    expect(AuditLog::query()->where('event', 'root_admin.revoked')->exists())->toBeTrue();
    $this->delete("/rootadmin/administrators/{$admin->id}", ['reason' => 'Teste'])->assertSessionHasErrors('administrator');
});

test('a RootAdmin without membership gets no company access through the common routes', function () {
    $company = Company::factory()->create();
    $this->actingAs(platformAdmin())->withSession(['active_company_id' => $company->id]);

    $this->get('/company/members')->assertNotFound();
    $this->get('/finance')->assertNotFound();
});

test('rootadmin:create accepts a numeric password with 8 characters', function () {
    $this->artisan('rootadmin:create', ['--name' => 'Administrador', '--email' => 'numerico@plataforma.test'])
        ->expectsQuestion('Senha (mínimo de 8 caracteres; pode ser só números)', '12345678')
        ->expectsQuestion('Confirme a senha', '12345678')
        ->assertSuccessful();

    expect(Hash::check('12345678', User::query()->where('email', 'numerico@plataforma.test')->sole()->password))->toBeTrue();
});
