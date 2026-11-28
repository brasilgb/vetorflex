<?php

use App\Models\User;
use App\Modules\Commercial\Models\Signup;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(fn () => $this->withoutVite());

/** Aplicação do catálogo pronta para venda (ou não), com dependências e valor opcionais. */
function catalogApplication(string $identifier, array $attributes = []): PlatformModule
{
    return PlatformModule::query()->updateOrCreate(['identifier' => $identifier], [
        'name' => Str::headline($identifier),
        'description' => "Aplicação {$identifier}",
        'development_status' => PlatformModule::IMPLEMENTED,
        'commercially_available' => true,
        'dependencies' => [],
        'permissions' => [],
        ...$attributes,
    ]);
}

function signupData(array $overrides = []): array
{
    return [
        'name' => 'Maria Empreendedora',
        'email' => 'maria@exemplo.test',
        'password' => 'senha-segura-123',
        'password_confirmation' => 'senha-segura-123',
        'company_name' => 'Doces da Maria',
        'company_document' => '',
        'modules' => ['sales'],
        'accept_terms' => true,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function verifyEmailOf(User $user)
{
    return test()->actingAs($user)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]));
}

test('the signup page lists only applications on sale, with the configured values', function () {
    catalogApplication('sales', ['monthly_price' => '49.90']);
    catalogApplication('finance');
    catalogApplication('fiscal', ['commercially_available' => false]);

    $this->get('/cadastro')->assertOk()->assertInertia(fn ($page) => $page->component('auth/register')
        ->has('applications', 2)
        ->where('applications.0.monthly_price', fn ($price) => $price === null || $price === '49.90')
        ->where('trialDays', 14));
});

test('the review recalculates dependencies and the total on the server', function () {
    catalogApplication('customers', ['monthly_price' => '10.00']);
    catalogApplication('sales', ['monthly_price' => '49.90', 'dependencies' => ['customers']]);
    catalogApplication('fiscal', ['commercially_available' => false]);

    $this->postJson('/cadastro/revisao', ['modules' => ['sales'], 'total' => '0.01'])->assertOk()
        ->assertJson(['selected' => ['sales'], 'required' => ['customers'], 'modules' => ['customers', 'sales'], 'total' => '59.90', 'unpriced' => []]);
    $this->postJson('/cadastro/revisao', ['modules' => ['fiscal']])->assertUnprocessable()->assertJsonValidationErrors('modules');
});

test('a new subscriber verifies the email and gets the company provisioned with dependencies and a 14-day trial', function () {
    Notification::fake();
    catalogApplication('customers');
    catalogApplication('sales', ['dependencies' => ['customers']]);
    catalogApplication('finance');

    $this->post('/cadastro', signupData(['modules' => ['sales'], 'company_document' => '11.222.333/0001-81']))->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'maria@exemplo.test')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeFalse()->and(Company::query()->count())->toBe(0);
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get('/dashboard')->assertRedirect(route('verification.notice'));

    verifyEmailOf($user)->assertRedirect();

    $company = Company::query()->sole();
    expect($company)->name->toBe('Doces da Maria')->document->toBe('11222333000181')->origin->toBe('public')->commercial_status->value->toBe('trial');
    expect($company->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue();
    expect($company->users()->wherePivot('role', 'owner')->pluck('users.id')->all())->toBe([$user->id]);
    expect(PlatformModule::query()->whereHas('companyModules', fn ($query) => $query->where('company_id', $company->id)->whereNull('deactivated_at'))->pluck('identifier')->sort()->values()->all())->toBe(['customers', 'sales']);
    expect(Signup::query()->sole())->status->toBe('provisioned')->company_id->toBe($company->id);

    $this->get('/dashboard')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->component('dashboard')
        ->where('overview.company.id', $company->id)
        ->where('overview.subscription.status', 'trial')
        ->has('overview.applications', 2));
});

test('signups reject unavailable applications, a taken email and missing terms acceptance', function () {
    catalogApplication('sales');
    catalogApplication('fiscal', ['commercially_available' => false]);
    User::factory()->create(['email' => 'ja@exemplo.test']);

    $this->post('/cadastro', signupData(['modules' => ['fiscal']]))->assertSessionHasErrors('modules');
    $this->post('/cadastro', signupData(['email' => 'ja@exemplo.test']))->assertSessionHasErrors('email');
    $this->post('/cadastro', signupData(['accept_terms' => false]))->assertSessionHasErrors('accept_terms');

    expect(Signup::query()->count())->toBe(0);
});

test('repeating the same signup does not duplicate it and needs the same password', function () {
    Notification::fake();
    catalogApplication('sales');
    $data = signupData();

    $this->post('/cadastro', $data)->assertRedirect(route('verification.notice'));
    auth()->logout();
    $this->post('/cadastro', $data)->assertRedirect(route('verification.notice'));
    auth()->logout();
    $this->post('/cadastro', [...$data, 'password' => 'outra-senha-123', 'password_confirmation' => 'outra-senha-123'])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1)->and(Signup::query()->count())->toBe(1);
});

test('a failed provisioning is retried safely on the next access', function () {
    Notification::fake();
    $sales = catalogApplication('sales');
    $this->post('/cadastro', signupData());
    $user = User::query()->sole();

    $sales->update(['commercially_available' => false]);
    verifyEmailOf($user);
    expect(Signup::query()->sole()->status)->toBe('failed')->and(Company::query()->count())->toBe(0);
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('pendingSignup', true));

    $sales->update(['commercially_available' => true]);
    $this->get('/dashboard')->assertRedirect('/dashboard');

    expect(Company::query()->count())->toBe(1)->and(Signup::query()->sole()->status)->toBe('provisioned');
    $this->get('/dashboard');
    expect(Company::query()->count())->toBe(1);
});

test('signups are rate limited', function () {
    catalogApplication('sales');

    foreach (range(1, 5) as $attempt) {
        $this->post('/cadastro', signupData(['accept_terms' => false]));
    }

    $this->post('/cadastro', signupData())->assertTooManyRequests();
});
