<?php

use App\Modules\Customers\Models\CustomerProfile;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Purchases\Models\SupplierImport;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;

const VALID_CNPJ = '11222333000181';

function supplierCsv(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('fornecedores.csv', "\xEF\xBB\xBF".$contents);
}

test('registers a simplified supplier with only type and name', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);

    $this->post('/purchases/suppliers', ['type' => 'individual', 'name' => 'Seu Zé das Frutas'])->assertSessionHasNoErrors();

    $supplier = SupplierProfile::query()->sole();
    expect($supplier->party)->name->toBe('Seu Zé das Frutas')->company_id->toBe($company->id);
    expect($supplier->party->hasRole(PartyRole::SUPPLIER))->toBeTrue();
    expect($supplier->party->hasRole(PartyRole::CUSTOMER))->toBeFalse();
});

test('a customer becomes a supplier without duplicating the person', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $party = Party::factory()->create(['company_id' => $company->id, 'type' => 'company', 'document' => VALID_CNPJ, 'name' => 'Cliente e Fornecedor', 'notes' => 'Nota do cliente']);
    $party->roles()->create(['company_id' => $company->id, 'role' => PartyRole::CUSTOMER]);
    CustomerProfile::create(['company_id' => $company->id, 'party_id' => $party->id]);
    $data = ['type' => 'company', 'name' => 'Outro nome', 'document' => '11.222.333/0001-81', 'internal_code' => 'F-1', 'notes' => 'Nota do fornecedor'];

    $this->post('/purchases/suppliers', $data)->assertSessionHasErrors('link_existing');
    $this->post('/purchases/suppliers', [...$data, 'link_existing' => true])->assertSessionHasNoErrors();
    $this->post('/purchases/suppliers', [...$data, 'internal_code' => 'F-2', 'link_existing' => true])->assertSessionHasErrors('document');

    expect(Party::query()->count())->toBe(1);
    expect($party->fresh())->name->toBe('Cliente e Fornecedor')->notes->toBe('Nota do cliente');
    expect($party->roles()->pluck('role')->sort()->values()->all())->toBe(['customer', 'supplier']);
    expect(SupplierProfile::query()->sole())->party_id->toBe($party->id)->notes->toBe('Nota do fornecedor');

    $supplier = SupplierProfile::query()->sole();
    $this->patch("/purchases/suppliers/{$supplier->id}/deactivate")->assertSessionHasNoErrors();
    expect($party->fresh()->is_active)->toBeTrue();
    expect($supplier->fresh()->is_active)->toBeFalse();
});

test('updates shared person data and validates internal code and document', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $supplier = SupplierProfile::factory()->create(['company_id' => $company->id, 'internal_code' => 'A']);
    $other = SupplierProfile::factory()->create(['company_id' => $company->id, 'internal_code' => 'B']);
    $other->party->update(['type' => 'company', 'document' => VALID_CNPJ]);

    $this->put("/purchases/suppliers/{$supplier->id}", ['type' => 'company', 'name' => 'X', 'internal_code' => 'B'])->assertSessionHasErrors('internal_code');
    $this->put("/purchases/suppliers/{$supplier->id}", ['type' => 'company', 'name' => 'X', 'document' => VALID_CNPJ])->assertSessionHasErrors('document');
    $this->put("/purchases/suppliers/{$supplier->id}", ['type' => 'company', 'name' => 'X', 'document' => '11.111.111/1111-11'])->assertSessionHasErrors('document');
    $this->put("/purchases/suppliers/{$supplier->id}", [
        'type' => 'company', 'name' => 'Nova Razão', 'lead_time_days' => 7, 'minimum_order' => '150.00',
        'addresses' => [['type' => 'main', 'city' => 'Campinas', 'state' => 'SP']],
        'contacts' => [['name' => 'Ana', 'position' => 'Vendas']],
    ])->assertSessionHasNoErrors();

    expect($supplier->fresh())->lead_time_days->toBe(7)->minimum_order->toBe('150.00');
    expect($supplier->party->fresh())->name->toBe('Nova Razão');
    expect($supplier->party->addresses()->sole()->city)->toBe('Campinas');
    expect($supplier->party->contacts()->sole()->name)->toBe('Ana');
});

test('lists, filters and shows suppliers with purchase data hidden without view_cost', function () {
    $company = Company::factory()->create();
    $active = SupplierProfile::factory()->create(['company_id' => $company->id]);
    SupplierProfile::factory()->inactive()->create(['company_id' => $company->id]);
    actingAsPurchasesMember($company, [PurchasePermission::View]);

    $this->get('/purchases/suppliers?active=1')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('purchases/suppliers/index')->has('suppliers.data', 1)->where('suppliers.data.0.id', $active->id));
    $this->getJson('/purchases/suppliers/lookup?term='.urlencode($active->party->name))->assertJsonCount(1);
    $this->get("/purchases/suppliers/{$active->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('purchases/suppliers/show')->missing('totals.value')->where('priceHistory', []));
});

test('links products to suppliers keeping a single preferred supplier per item', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $variant = purchasableVariant($company);
    $first = SupplierProfile::factory()->create(['company_id' => $company->id]);
    $second = SupplierProfile::factory()->create(['company_id' => $company->id]);

    $this->post("/purchases/suppliers/{$first->id}/products", ['product_variant_id' => $variant->id, 'supplier_code' => 'ABC', 'is_preferred' => true])->assertSessionHasNoErrors();
    $this->post("/purchases/suppliers/{$second->id}/products", ['product_variant_id' => $variant->id, 'is_preferred' => true])->assertSessionHasNoErrors();
    $this->post("/purchases/suppliers/{$second->id}/products", ['product_variant_id' => $variant->id])->assertSessionHasErrors('product_variant_id');

    expect(SupplierProduct::query()->where('is_preferred', true)->sole()->supplier_profile_id)->toBe($second->id);
    $this->getJson("/purchases/items/lookup?term=ABC&supplier={$first->id}")->assertJsonPath('0.id', $variant->id)->assertJsonPath('0.supplier_code', 'ABC');

    $link = SupplierProduct::query()->where('supplier_profile_id', $first->id)->sole();
    $this->delete("/purchases/suppliers/{$second->id}/products/{$link->id}")->assertNotFound();
    $this->delete("/purchases/suppliers/{$first->id}/products/{$link->id}")->assertSessionHasNoErrors();
    expect(SupplierProduct::query()->count())->toBe(1);
});

test('imports suppliers with preview, existing people and confirmation', function () {
    $company = Company::factory()->create();
    $user = actingAsPurchasesMember($company);
    $customer = Party::factory()->create(['company_id' => $company->id, 'type' => 'company', 'document' => VALID_CNPJ, 'name' => 'Cliente Antigo']);
    $csv = implode("\n", [
        'nome;tipo;documento;codigo_interno;prazo_entrega_dias;pedido_minimo',
        'Distribuidora Nova;juridica;;D-1;5;1.200,50',
        'Ligação;juridica;11.222.333/0001-81;D-2;;',
        ';fisica;;;;',
        'CPF errado;fisica;111.111.111-11;;;',
        'Prazo inválido;fisica;;;abc;',
    ]);

    $this->post('/purchases/suppliers/imports', ['file' => supplierCsv($csv)])->assertRedirect();
    $import = SupplierImport::query()->sole();
    expect(SupplierProfile::query()->count())->toBe(0);
    $this->get("/purchases/suppliers/imports/{$import->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('purchases/suppliers/import')
        ->where('import.rows.0.status', 'new')->where('import.rows.1.status', 'link')->where('import.rows.1.party_name', 'Cliente Antigo')
        ->where('import.rows.2.status', 'invalid')->where('import.rows.3.status', 'invalid')->where('import.rows.4.status', 'invalid'));

    $this->post("/purchases/suppliers/imports/{$import->id}/confirm", ['link_existing' => true])->assertSessionHasNoErrors();
    $this->post("/purchases/suppliers/imports/{$import->id}/confirm", ['link_existing' => true]);

    expect($import->fresh())->status->toBe(SupplierImport::COMPLETED)->rows->toBeNull()
        ->and($import->fresh()->report)->toMatchArray(['created' => 1, 'linked' => 1, 'failed' => 3]);
    expect(SupplierProfile::query()->count())->toBe(2);
    expect(SupplierProfile::query()->where('internal_code', 'D-1')->sole())->lead_time_days->toBe(5)->minimum_order->toBe('1200.50');
    expect($customer->fresh()->name)->toBe('Cliente Antigo');
    expect($customer->supplierProfile()->exists())->toBeTrue();
    expect($import->fresh()->user_id)->toBe($user->id);
});

test('exports suppliers protected against CSV injection', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    SupplierProfile::factory()->create(['company_id' => $company->id, 'party_id' => Party::factory()->create(['company_id' => $company->id, 'name' => '=HYPERLINK("x")'])->id]);

    $content = $this->get('/purchases/suppliers/export')->assertOk()->streamedContent();

    expect($content)->toContain("'=HYPERLINK")->toContain('nome;tipo;nome_fantasia');
});

test('another user cannot see an import preview', function () {
    $company = Company::factory()->create();
    actingAsPurchasesMember($company);
    $this->post('/purchases/suppliers/imports', ['file' => supplierCsv("nome\nTeste")]);
    $import = SupplierImport::query()->sole();

    actingAsPurchasesMember($company);
    $this->get("/purchases/suppliers/imports/{$import->id}")->assertNotFound();
});
