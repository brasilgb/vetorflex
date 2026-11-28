<?php

use App\Models\User;
use App\Modules\Commercial\Data\ProvisioningRequest;
use App\Modules\Commercial\Models\ContactRequest;
use App\Modules\Commercial\Services\CompanyProvisioning;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Customers\Enums\CustomerPermission;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\PlatformModuleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

function commercialModule(string $identifier, array $attributes = []): PlatformModule
{
    return PlatformModule::query()->updateOrCreate(['identifier' => $identifier], [
        'name' => Str::headline($identifier),
        'description' => "Aplicação {$identifier}",
        'development_status' => PlatformModule::IMPLEMENTED,
        'commercially_available' => true,
        'dependencies' => [],
        'permissions' => $identifier === 'customers' ? CustomerPermission::catalog() : [],
        ...$attributes,
    ]);
}

/** Empresa em teste criada pelo serviço de provisionamento, com o proprietário autenticado. */
function trialCompany(array $modules = ['customers'], int $trialDays = 14): array
{
    foreach ($modules as $identifier) {
        commercialModule($identifier);
    }

    $owner = User::factory()->create();
    $company = app(CompanyProvisioning::class)->provision(new ProvisioningRequest(key: (string) Str::uuid(), owner: $owner, actor: $owner, companyName: 'Loja em Teste', modules: $modules, trialDays: $trialDays));
    test()->actingAs($owner)->withSession(['active_company_id' => $company->id]);

    return [$company, $owner];
}

test('the landing page shows the real catalog and the legal pages render', function () {
    commercialModule('sales', ['monthly_price' => '39.90', 'tagline' => 'Vendas sem complicação']);
    commercialModule('fiscal', ['commercially_available' => false]);

    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('welcome')->has('applications', 1)->where('applications.0.tagline', 'Vendas sem complicação')->where('applications.0.monthly_price', '39.90'));
    $this->get('/privacidade')->assertOk()->assertInertia(fn ($page) => $page->component('legal/privacy'));
    $this->get('/termos')->assertOk()->assertInertia(fn ($page) => $page->component('legal/terms')->where('trialDays', 14));
});

test('the contact form stores the request and rejects bots', function () {
    $this->post('/contato', ['name' => 'João', 'email' => 'joao@exemplo.test', 'message' => 'Quero conhecer o PDV.', 'modules' => ['pos']])->assertSessionHasNoErrors();
    $this->post('/contato', ['name' => 'Bot', 'email' => 'bot@exemplo.test', 'message' => 'spam', 'website' => 'http://spam'])->assertSessionHasErrors('website');

    expect(ContactRequest::query()->sole())->name->toBe('João')->modules->toBe(['pos']);
});

test('after the trial the data stays readable but writes are blocked until a contract is registered', function () {
    [$company] = trialCompany();
    $this->post('/customers', ['type' => 'individual', 'name' => 'Cliente Antes'])->assertSessionHasNoErrors();

    $this->travel(15)->days();

    $this->get('/customers')->assertOk();
    $this->post('/customers', ['type' => 'individual', 'name' => 'Cliente Depois'])->assertSessionHasErrors('subscription');
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('overview.subscription.status', 'expired')->where('overview.subscription.blocks_writes', true));
    expect(PartyRole::query()->where('company_id', $company->id)->count())->toBe(1);

    $admin = User::factory()->create(['is_root_admin' => true]);
    $this->actingAs($admin)->patch("/rootadmin/companies/{$company->id}/contract", ['reason' => 'Proposta 2026-001 aceita'])->assertSessionHasNoErrors();
    expect($company->refresh()->commercial_status->value)->toBe('active')->and($company->contracted_at)->not->toBeNull();
    expect(AuditLog::query()->where('company_id', $company->id)->where('event', 'company.contracted')->exists())->toBeTrue();
});

test('the root admin extends a trial with an audited reason, never into the past', function () {
    [$company] = trialCompany();
    $admin = User::factory()->create(['is_root_admin' => true]);
    $this->actingAs($admin);
    $before = $company->trial_ends_at;

    $this->patch("/rootadmin/companies/{$company->id}/trial", ['trial_ends_at' => now()->subDay()->format('Y-m-d'), 'reason' => 'Teste'])->assertSessionHasErrors('trial_ends_at');
    $this->patch("/rootadmin/companies/{$company->id}/trial", ['trial_ends_at' => now()->addDays(30)->format('Y-m-d'), 'reason' => ''])->assertSessionHasErrors('reason');
    $this->patch("/rootadmin/companies/{$company->id}/trial", ['trial_ends_at' => now()->addDays(30)->format('Y-m-d'), 'reason' => 'Cliente aguardando certificado digital'])->assertSessionHasNoErrors();

    expect($company->refresh()->trial_ends_at->gt($before))->toBeTrue();
    expect(AuditLog::query()->where('company_id', $company->id)->where('event', 'company.trial_extended')->first()->context['reason'])->toBe('Cliente aguardando certificado digital');
});

test('deactivating and reactivating an application never restarts the trial', function () {
    [$company, $owner] = trialCompany(['customers', 'sales']);
    $endsAt = $company->trial_ends_at->toIso8601String();

    $this->delete('/company/applications/sales')->assertSessionHasNoErrors();
    $this->post('/company/applications', ['modules' => ['sales']])->assertSessionHasNoErrors();

    expect($company->refresh()->trial_ends_at->toIso8601String())->toBe($endsAt);
});

test('the owner adds applications with their dependencies and cannot remove a required one', function () {
    commercialModule('customers');
    commercialModule('sales', ['dependencies' => ['customers'], 'monthly_price' => '20.00']);
    [$company] = trialCompany(['finance']);

    $this->get('/company/applications')->assertInertia(fn ($page) => $page->component('company/applications')->where('active', ['finance'])->where('canManage', true));
    $this->post('/company/applications', ['modules' => ['sales'], 'monthly_price' => '0'])->assertSessionHasNoErrors();
    $this->delete('/company/applications/customers')->assertSessionHasErrors('module');

    expect(app(CompanyProvisioning::class)->activeIdentifiers($company))->toEqualCanonicalizing(['finance', 'customers', 'sales']);
});

test('only the owner manages applications', function () {
    [$company] = trialCompany(['customers']);
    $operator = User::factory()->create();
    $operator->companies()->attach($company, ['role' => 'operator', 'is_active' => true, 'permissions' => []]);
    $this->actingAs($operator)->withSession(['active_company_id' => $company->id]);

    $this->post('/company/applications', ['modules' => ['customers']])->assertForbidden();
    $this->delete('/company/applications/customers')->assertForbidden();
});

test('the root admin provisions a company with the same service, linking or inviting the owner', function () {
    Notification::fake();
    commercialModule('customers');
    commercialModule('sales', ['dependencies' => ['customers'], 'commercially_available' => false]);
    $admin = User::factory()->create(['is_root_admin' => true]);
    $existing = User::factory()->create(['email' => 'dono@exemplo.test']);
    $this->actingAs($admin);

    $this->post('/rootadmin/companies', ['name' => 'Mercado Bom', 'slug' => 'mercado-bom', 'owner_name' => 'Dono', 'owner_email' => 'DONO@exemplo.test', 'modules' => ['sales'], 'trial_days' => 30])->assertSessionHasNoErrors();
    $this->post('/rootadmin/companies', ['name' => 'Oficina Nova', 'slug' => 'oficina-nova', 'owner_name' => 'Nova Dona', 'owner_email' => 'nova@exemplo.test', 'modules' => []])->assertSessionHasNoErrors();

    $market = Company::query()->where('slug', 'mercado-bom')->sole();
    expect($market)->origin->toBe('admin')->commercial_status->value->toBe('trial');
    expect($market->trial_ends_at->isSameDay(now()->addDays(30)))->toBeTrue();
    expect(app(CompanyProvisioning::class)->activeIdentifiers($market))->toEqualCanonicalizing(['customers', 'sales']);
    expect($market->users()->pluck('users.id')->all())->toBe([$existing->id]);

    $workshop = Company::query()->where('slug', 'oficina-nova')->sole();
    expect($workshop->commercial_status->value)->toBe('active');
    $newOwner = User::query()->where('email', 'nova@exemplo.test')->sole();
    Notification::assertSentTo($newOwner, ResetPassword::class);
    Notification::assertNotSentTo($existing, ResetPassword::class);

    $this->get('/rootadmin/companies?commercial=trial')->assertInertia(fn ($page) => $page->has('companies', 1)->where('companies.0.slug', 'mercado-bom')->where('counts.active', 1));
});

test('the root admin edits the commercial catalog and a new seed keeps those values', function () {
    $this->seed(PlatformModuleSeeder::class);
    $sales = PlatformModule::query()->where('identifier', 'sales')->sole();
    $this->actingAs(User::factory()->create(['is_root_admin' => true]));

    $this->put("/rootadmin/modules/{$sales->id}/commercial", ['name' => 'Vendas Plus', 'tagline' => 'Venda mais', 'description' => 'Orçamentos e vendas.', 'benefits' => ['Rápido', ' ', 'Simples'], 'category' => 'Vendas', 'icon' => 'receipt', 'sort_order' => 3, 'monthly_price' => '59.90'])->assertSessionHasNoErrors();
    $this->seed(PlatformModuleSeeder::class);

    expect($sales->refresh())->name->toBe('Vendas Plus')->tagline->toBe('Venda mais')->benefits->toBe(['Rápido', 'Simples'])->monthly_price->toBe('59.90')->sort_order->toBe(3);
    $this->actingAs(User::factory()->create())->put("/rootadmin/modules/{$sales->id}/commercial", ['name' => 'X'])->assertForbidden();
});

test('a user with a single company lands on it, and indicators only count that company', function () {
    [$company, $owner] = trialCompany(['customers']);
    $this->post('/customers', ['type' => 'individual', 'name' => 'Cliente da Loja'])->assertSessionHasNoErrors();
    trialCompany(['customers']);
    $this->post('/customers', ['type' => 'individual', 'name' => 'Cliente de Outra'])->assertSessionHasNoErrors();

    $this->actingAs($owner)->withSession([])->get('/dashboard')->assertRedirect('/dashboard');
    $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('overview.company.id', $company->id)
        ->where('overview.applications.0.identifier', 'customers')
        ->where('overview.applications.0.indicators.0.value', 1));
});

test('the expiry routine marks overdue trials without touching contracted companies', function () {
    [$company] = trialCompany();
    $contracted = Company::factory()->create();
    $this->travel(20)->days();

    $this->artisan('commercial:expire-trials')->assertSuccessful();

    expect($company->refresh()->commercial_status->value)->toBe('expired')->and($contracted->refresh()->commercial_status->value)->toBe('active');
    expect(CompanyModule::query()->where('company_id', $company->id)->whereNull('deactivated_at')->count())->toBe(1);
});
