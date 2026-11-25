<?php

use App\Models\User;
use App\Modules\Customers\Enums\CustomerPermission;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\CustomerDemoSeeder;
use Database\Seeders\PlatformModuleSeeder;
use Illuminate\Http\UploadedFile;

function customerUserFor(Company $company): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->companies()->attach($company, ['role' => 'owner', 'is_active' => true]);
    $module = PlatformModule::create(['identifier' => 'customers', 'name' => 'Clientes', 'description' => 'Base de clientes.', 'permissions' => CustomerPermission::catalog()]);
    CompanyModule::create(['company_id' => $company->id, 'platform_module_id' => $module->id, 'activated_by' => $user->id, 'activated_at' => now()]);

    return $user;
}

test('creates a customer with its commercial role and profile in the active company', function () {
    $company = Company::factory()->create();
    $user = customerUserFor($company);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])->post('/customers', [
        'type' => 'individual',
        'name' => 'Maria da Silva',
        'document' => '529.982.247-25',
        'email' => 'maria@example.test',
        'addresses' => [['type' => 'main', 'street' => 'Rua A', 'city' => 'São Paulo', 'state' => 'SP']],
    ])->assertRedirect();

    $party = Party::query()->where('company_id', $company->id)->firstOrFail();
    $this->assertDatabaseHas('party_roles', ['company_id' => $company->id, 'party_id' => $party->id, 'role' => PartyRole::CUSTOMER]);
    $this->assertDatabaseHas('customer_profiles', ['company_id' => $company->id, 'party_id' => $party->id]);
    $this->assertDatabaseHas('party_addresses', ['company_id' => $company->id, 'party_id' => $party->id, 'street' => 'Rua A']);
});

test('does not expose a customer from another company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $user = customerUserFor($company);
    $customer = Party::create(['company_id' => $otherCompany->id, 'type' => 'individual', 'name' => 'Outro Cliente']);
    $customer->roles()->create(['company_id' => $otherCompany->id, 'role' => PartyRole::CUSTOMER]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get("/customers/{$customer->id}")
        ->assertNotFound();
});

test('imports valid CSV rows and skips invalid documents without aborting the import', function () {
    $company = Company::factory()->create();
    $user = customerUserFor($company);
    $csv = "tipo,nome,documento,email\nindividual,Ana Silva,52998224725,ana@example.test\nindividual,Documento inválido,11111111111,invalido@example.test,ignored";

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->post('/customers/import', ['file' => UploadedFile::fake()->createWithContent('clientes.csv', $csv)])
        ->assertRedirect('/customers');

    $this->assertDatabaseHas('parties', ['company_id' => $company->id, 'name' => 'Ana Silva', 'document' => '52998224725']);
    $this->assertDatabaseMissing('parties', ['company_id' => $company->id, 'name' => 'Documento inválido']);
    $this->assertDatabaseHas('customer_imports', ['company_id' => $company->id, 'status' => 'completed']);
});

test('returns 403 when the active company member lacks the requested customer permission', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->companies()->attach($company, ['role' => 'operator', 'is_active' => true, 'permissions' => []]);
    $module = PlatformModule::create(['identifier' => 'customers', 'name' => 'Clientes', 'description' => 'Base de clientes.', 'permissions' => CustomerPermission::catalog()]);
    CompanyModule::create(['company_id' => $company->id, 'platform_module_id' => $module->id, 'activated_by' => $user->id, 'activated_at' => now()]);

    $this->actingAs($user)->withSession(['active_company_id' => $company->id])
        ->get('/customers')
        ->assertForbidden();
});

test('neutralizes spreadsheet formulas in the customer export and restores them on import', function () {
    $company = Company::factory()->create();
    $user = customerUserFor($company);
    $customer = Party::create(['company_id' => $company->id, 'type' => 'individual', 'name' => '=HYPERLINK("http://evil.test")', 'phone' => '+5511999990000']);
    $customer->roles()->create(['company_id' => $company->id, 'role' => PartyRole::CUSTOMER]);

    $csv = $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get('/customers/export')->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")->toContain("'+5511999990000")->not->toContain(',"=HYPERLINK');

    $this->post('/customers/import', ['file' => UploadedFile::fake()->createWithContent('clientes.csv', "nome,telefone\nAna Silva,'+5511999990000")])->assertRedirect('/customers');

    $this->assertDatabaseHas('parties', ['company_id' => $company->id, 'name' => 'Ana Silva', 'phone' => '+5511999990000']);
});

test('demo seeder creates the known-password account only in local and testing environments', function (string $environment, bool $isCreated) {
    $this->seed(PlatformModuleSeeder::class);
    $this->app->detectEnvironment(fn (): string => $environment);

    (new CustomerDemoSeeder)->run();

    expect(User::query()->where('email', 'demo@vetornew.test')->exists())->toBe($isCreated);
})->with([
    'production' => ['production', false],
    'staging' => ['staging', false],
    'local' => ['local', true],
]);
