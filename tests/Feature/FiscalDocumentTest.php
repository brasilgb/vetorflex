<?php

use App\Models\User;
use App\Modules\Finance\Models\FinanceMovement;
use App\Modules\Finance\Models\FinanceSettlement;
use App\Modules\Finance\Models\FinanceTitle;
use App\Modules\Fiscal\Enums\FiscalOrigin;
use App\Modules\Fiscal\Enums\FiscalPermission;
use App\Modules\Fiscal\Enums\FiscalStatus;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalDocumentEvent;
use App\Modules\Fiscal\Models\FiscalProviderAvailability;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Models\FiscalWebhookEvent;
use App\Modules\Fiscal\Providers\Spedy\SpedyProvider;
use App\Modules\Fiscal\Services\FiscalDocumentService;
use App\Modules\Fiscal\Services\FiscalOrigins;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('fiscal');
});

function fiscalDraft(array $overrides = []): FiscalDocument
{
    test()->post('/fiscal/documents', nfseDraftData($overrides))->assertSessionHasNoErrors();

    return FiscalDocument::query()->latest('id')->firstOrFail();
}

test('the module requires activation and individual permissions', function () {
    $company = Company::factory()->create();
    $user = actingAsFiscalMember($company, [FiscalPermission::View]);

    $this->get('/fiscal/documents')->assertOk();
    $this->post('/fiscal/documents', nfseDraftData())->assertForbidden();
    $this->get('/fiscal/settings')->assertForbidden();

    // Empresa sem o módulo contratado.
    $other = Company::factory()->create();
    $user->companies()->attach($other, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
    $this->withSession(['active_company_id' => $other->id])->get('/fiscal/documents')->assertForbidden();
});

test('saving a draft never transmits and repeating the request does not duplicate it', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $data = nfseDraftData();

    $this->post('/fiscal/documents', $data)->assertSessionHasNoErrors();
    $this->post('/fiscal/documents', $data)->assertSessionHasNoErrors();

    $document = FiscalDocument::query()->sole();
    expect($document)->status->toBe(FiscalStatus::Draft)->total->toBe('500.00')->integration_id->toBeString();
    expect($spedy->calls)->toBe([]);
});

test('documents of another company answer not found', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();

    actingAsFiscalMember(Company::factory()->create());

    $this->get("/fiscal/documents/{$document->id}")->assertNotFound();
    $this->post("/fiscal/documents/{$document->id}/issue")->assertNotFound();
    $this->get("/fiscal/documents/{$document->id}/files/xml")->assertNotFound();
});

test('issuing is blocked while the configuration is incomplete, listing what is missing', function () {
    fiscalReady($company = Company::factory()->create(), ['tax_settings_confirmed_at' => null, 'certificate_expires_at' => now()->subDay()]);
    actingAsFiscalMember($company);
    $document = fiscalDraft();

    $this->get("/fiscal/documents/{$document->id}")->assertInertia(fn ($page) => $page->component('fiscal/documents/show')->where('preview.ready', false)
        ->where('preview.blockers', fn ($blockers) => collect($blockers)->contains(fn ($text) => str_contains($text, 'certificado')) && collect($blockers)->contains(fn ($text) => str_contains($text, 'Confirme'))));
    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasErrors('fiscal');

    expect($document->refresh()->status)->toBe(FiscalStatus::Draft);
});

test('production needs the platform release and a production platform account', function () {
    fiscalReady($company = Company::factory()->create(), ['environment' => 'production']);
    actingAsFiscalMember($company);
    $document = fiscalDraft();

    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasErrors('fiscal');
    expect($document->refresh()->status)->toBe(FiscalStatus::Draft);
});

test('an nfs-e is authorized by the simulated provider, with files kept privately and checked by hash', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();

    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasNoErrors();

    $document->refresh();
    expect($document)->status->toBe(FiscalStatus::Authorized)->number->toBe('1001')->provider_reference->not->toBeNull()->xml_sha256->toHaveLength(64);
    expect($spedy->payloads[0])->integrationId->toBe($document->integration_id)->federalServiceCode->toBe('01.07')->taxationType->toBe('taxationInMunicipality');
    expect($spedy->payloads[0]['total'])->toMatchArray(['invoiceAmount' => 500.0, 'issRate' => 2.0]);
    Storage::disk('fiscal')->assertExists($document->xml_path);
    expect($document->xml_path)->toStartWith("{$company->id}/nfse/");

    $this->get("/fiscal/documents/{$document->id}/files/xml")->assertOk()->assertHeader('Content-Type', 'application/xml')->assertSee('demonstracao', false);

    // Arquivo adulterado: a cópia é refeita a partir do provedor antes de ser entregue.
    Storage::disk('fiscal')->put($document->xml_path, '<adulterado/>');
    $this->get("/fiscal/documents/{$document->id}/files/xml")->assertOk()->assertDontSee('adulterado', false);
});

test('downloading files needs the download permission', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $this->post("/fiscal/documents/{$document->id}/issue");

    actingAsFiscalMember($company, [FiscalPermission::View]);

    $this->get("/fiscal/documents/{$document->id}/files/xml")->assertForbidden();
    $this->get("/fiscal/documents/{$document->id}/files/pdf")->assertForbidden();
});

test('a rejected document is corrected and resent with the same integration id, without a second document', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $spedy->nextOutcome = 'rejected';

    $this->post("/fiscal/documents/{$document->id}/issue");
    expect($document->refresh())->status->toBe(FiscalStatus::Rejected)->error_message->toContain('[539]');

    $this->put("/fiscal/documents/{$document->id}", nfseDraftData(['notes' => 'Corrigido após rejeição']))->assertSessionHasNoErrors();
    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasNoErrors();

    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);
    expect($spedy->invoices)->toHaveCount(1);
    expect(array_column($spedy->payloads, 'integrationId'))->toBe([$document->integration_id, $document->integration_id]);
});

test('communication failures are kept for resend and the provider is consulted before resending', function (string $outcome) {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $spedy->nextOutcome = $outcome;

    $this->post("/fiscal/documents/{$document->id}/issue");
    expect($document->refresh())->status->toBe(FiscalStatus::CommunicationFailure)->error_message->not->toBeNull();

    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasNoErrors();

    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);
    expect($spedy->calls)->toContain('GET /service-invoices');
    expect($spedy->invoices)->toHaveCount(1);
})->with(['timeout', 'server_error']);

test('a request refused by the provider becomes a rejection with the reason', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $spedy->nextOutcome = 'bad_request';

    $this->post("/fiscal/documents/{$document->id}/issue");

    expect($document->refresh())->status->toBe(FiscalStatus::Rejected)->error_message->toContain('CPF/CNPJ inválido');
});

test('a document still processing is settled by consulting the provider, and old statuses never go back', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $spedy->nextOutcome = 'enqueued';

    $this->post("/fiscal/documents/{$document->id}/issue");
    expect($document->refresh()->status)->toBe(FiscalStatus::Processing);

    $spedy->settle((string) $document->provider_reference, 'authorized');
    $this->post("/fiscal/documents/{$document->id}/refresh")->assertSessionHasNoErrors();
    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);

    $stale = app(SpedyProvider::class)->toDocument(['id' => $document->provider_reference, 'status' => 'enqueued']);
    DB::transaction(fn () => app(FiscalDocumentService::class)->apply(FiscalDocument::query()->lockForUpdate()->findOrFail($document->id), $stale, null, 'teste'));
    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);
});

test('reconciliation consults pending documents without user action', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $spedy->nextOutcome = 'enqueued';
    $this->post("/fiscal/documents/{$document->id}/issue");
    $spedy->settle((string) $document->refresh()->provider_reference, 'authorized');
    FiscalDocument::query()->whereKey($document->id)->update(['updated_at' => now()->subMinutes(5)]);

    $this->artisan('fiscal:reconcile')->assertSuccessful();

    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);
});

test('cancelling needs a 15 to 255 character reason and keeps the document authorized when refused', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $this->post("/fiscal/documents/{$document->id}/issue");

    $this->post("/fiscal/documents/{$document->id}/cancel", ['reason' => 'curto'])->assertSessionHasErrors('reason');

    $spedy->nextCancel = 'refused';
    $this->post("/fiscal/documents/{$document->id}/cancel", ['reason' => 'Serviço não prestado ao tomador'])->assertSessionHasErrors('reason');
    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);

    $spedy->nextCancel = 'canceled';
    $this->post("/fiscal/documents/{$document->id}/cancel", ['reason' => 'Serviço não prestado ao tomador'])->assertSessionHasNoErrors();
    expect($document->refresh())->status->toBe(FiscalStatus::Cancelled)->cancel_reason->toBe('Serviço não prestado ao tomador');
    expect(FiscalDocumentEvent::query()->where('fiscal_document_id', $document->id)->pluck('event'))->toContain('cancel_refused', 'cancel_requested');
});

test('a goods invoice is blocked with the exact missing fiscal data, never approximated', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft([
        'type' => 'nfe',
        'receiver' => ['name' => 'Comprador', 'document' => '11.222.333/0001-81', 'address' => ['postal_code' => '01001000', 'street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP']],
        'items' => [['description' => 'Caneca', 'quantity' => '2', 'unit_price' => '15.00']],
    ]);

    $this->get("/fiscal/documents/{$document->id}")->assertInertia(fn ($page) => $page->where('preview.problems', fn ($problems) => collect($problems)->contains('Informe o NCM (8 dígitos) de "Caneca".')));
    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasErrors('fiscal');
    expect($spedy->payloads)->toBe([]);

    $this->put("/fiscal/documents/{$document->id}", ['receiver' => $document->receiver, 'items' => [['description' => 'Caneca', 'quantity' => '2', 'unit_price' => '15.00', 'ncm' => '69120000']]])->assertSessionHasNoErrors();
    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasNoErrors();

    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);
    expect($spedy->payloads[0]['items'][0])->toMatchArray(['ncm' => '69120000', 'cfop' => 5102, 'unit' => 'UN'])->and($spedy->payloads[0]['items'][0]['taxes']['icms'])->toBe(['origin' => 0, 'csosn' => 102]);
});

test('webhooks need a valid signature, are deduplicated and confirm the status by consulting the provider', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $spedy->nextOutcome = 'enqueued';
    $this->post("/fiscal/documents/{$document->id}/issue");
    $reference = (string) $document->refresh()->provider_reference;
    $spedy->settle($reference, 'authorized');
    $payload = ['id' => 'msg_1', 'event' => 'invoice.status_changed', 'data' => ['id' => $reference, 'integrationId' => $document->integration_id, 'status' => 'authorized', 'company' => ['federalTaxNumber' => '11222333000181']]];

    spedyWebhook($payload, secret: 'whsec_'.base64_encode('outro-segredo'))->assertUnauthorized();
    spedyWebhook($payload, timestamp: time() - 3600)->assertUnauthorized();
    expect($document->refresh()->status)->toBe(FiscalStatus::Processing);

    spedyWebhook($payload)->assertOk();
    spedyWebhook($payload)->assertOk();

    expect($document->refresh()->status)->toBe(FiscalStatus::Authorized);
    expect(FiscalWebhookEvent::query()->sole())->status->toBe('processed')->fiscal_document_id->toBe($document->id);
    expect(collect($spedy->calls)->filter(fn ($call) => $call === "GET /service-invoices/{$reference}"))->toHaveCount(1);
});

test('a webhook naming another issuer cnpj is ignored', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $spedy->nextOutcome = 'enqueued';
    $this->post("/fiscal/documents/{$document->id}/issue");

    spedyWebhook(['id' => 'msg_2', 'event' => 'invoice.status_changed', 'data' => ['id' => $document->refresh()->provider_reference, 'status' => 'authorized', 'company' => ['federalTaxNumber' => '99888777000166']]], id: 'msg_2')->assertOk();

    expect(FiscalWebhookEvent::query()->sole())->status->toBe('ignored')->fiscal_document_id->toBeNull();
    expect($document->refresh()->status)->toBe(FiscalStatus::Processing);
});

test('a sale gets one fiscal document, without changing the sale, stock or finance', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company, null, ['sales', 'products', 'finance']);
    $variant = sellableVariant($company, '40.00', '10');
    $variant->product->update(['ncm' => '61091000', 'merchandise_origin' => '0']);
    $customer = saleCustomer($company, ['document' => '11.222.333/0001-81']);
    $customer->addresses()->create(['company_id' => $company->id, 'type' => 'billing', 'postal_code' => '01001000', 'street' => 'Rua B', 'number' => '2', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP']);
    $this->post('/sales/orders', saleOrderData($customer, [['product_variant_id' => $variant->id, 'quantity' => '2']], ['payment_condition' => 'cash', 'payment_method' => 'pix']))->assertSessionHasNoErrors();
    $order = SaleOrder::query()->sole();
    $this->post("/sales/orders/{$order->id}/confirm")->assertSessionHasNoErrors();
    $before = [SaleOrder::query()->sole()->toArray(), InventoryMovement::query()->count(), FinanceTitle::query()->count(), FinanceSettlement::query()->count()];

    $this->get("/fiscal/documents/create?origin=sale&origin_id={$order->id}")->assertInertia(fn ($page) => $page->component('fiscal/documents/form')->where('prefill.suggested_type', 'nfe')->where('prefill.items.0.ncm', '61091000'));
    $this->post('/fiscal/documents', ['type' => 'nfe', 'origin' => 'sale', 'origin_id' => $order->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $document = FiscalDocument::query()->sole();
    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasNoErrors();
    $this->post('/fiscal/documents', ['type' => 'nfe', 'origin' => 'sale', 'origin_id' => $order->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('origin_id');

    expect($document->refresh())->status->toBe(FiscalStatus::Authorized)->origin_type->toBe('sale_order')->total->toBe('80.00');
    expect($spedy->payloads[0]['receiver']['federalTaxNumber'])->toBe('11222333000181');
    expect([SaleOrder::query()->sole()->toArray(), InventoryMovement::query()->count(), FinanceTitle::query()->count(), FinanceSettlement::query()->count()])->toBe($before);
    $this->get("/sales/orders/{$order->id}")->assertInertia(fn ($page) => $page->where('fiscalDocuments.documents.0.id', $document->id));
});

test('sale documents need the sales module', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);

    $this->post('/fiscal/documents', nfseDraftData(['origin' => 'sale', 'origin_id' => 1]))->assertSessionHasErrors('origin');
});

test('a completed pos sale gets an nfc-e kept apart from the non-fiscal receipt and the cash register', function () {
    $spedy = fiscalReady($company = Company::factory()->create());
    $user = actingAsPosMember($company, null, ['products']);
    actingAsFiscalMember($company, null, ['pos'], $user);
    $session = openPosSession($company);
    $this->postJson("/pos/sessions/{$session->id}/sales", posCart([['description' => 'Café coado', 'unit_price' => '6.00', 'quantity' => '2']], [['method' => 'cash', 'amount' => '12.00']]))->assertCreated();
    $sale = PosSale::query()->sole();
    $movements = FinanceMovement::query()->count();

    $this->get("/fiscal/documents/create?origin=pos&origin_id={$sale->id}")->assertInertia(fn ($page) => $page->where('prefill.suggested_type', 'nfce'));
    $draft = app(FiscalOrigins::class)->resolve($company, FiscalOrigin::Pos, $sale->id);
    $items = array_map(fn ($item) => [...$item, 'ncm' => '09012100'], $draft['items']);
    $this->post('/fiscal/documents', ['type' => 'nfce', 'origin' => 'pos', 'origin_id' => $sale->id, 'items' => $items, 'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $document = FiscalDocument::query()->sole();
    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasNoErrors();

    expect($document->refresh())->status->toBe(FiscalStatus::Authorized)->total->toBe('12.00');
    expect($spedy->calls)->toContain('POST /consumer-invoices');
    expect($spedy->payloads[0])->not->toHaveKey('receiver');
    expect(FinanceMovement::query()->count())->toBe($movements);
    $this->get("/pos/sales/{$sale->id}")->assertInertia(fn ($page) => $page->where('fiscalDocuments.documents.0.status', 'authorized'));
});

test('a receivable gets a service invoice without settling it', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company, null, ['finance']);
    $this->post('/finance/titles', financeTitleData('receivable', ['amount' => '300.00', 'description' => 'Consultoria mensal']))->assertSessionHasNoErrors();
    $title = FinanceTitle::query()->sole();

    $this->post('/fiscal/documents', ['type' => 'nfse', 'origin' => 'finance', 'origin_id' => $title->id, 'receiver' => ['name' => 'Cliente', 'document' => '52998224725'], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    $document = FiscalDocument::query()->sole();
    $this->post("/fiscal/documents/{$document->id}/issue")->assertSessionHasNoErrors();

    expect($document->refresh())->status->toBe(FiscalStatus::Authorized)->total->toBe('300.00');
    expect($title->refresh()->status->isOpen())->toBeTrue();
    expect(FinanceSettlement::query()->count())->toBe(0);
});

test('deactivating the module blocks access but keeps the documents', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $this->post("/fiscal/documents/{$document->id}/issue");

    CompanyModule::query()->where('company_id', $company->id)->update(['deactivated_at' => now()]);

    $this->get('/fiscal/documents')->assertForbidden();
    expect(FiscalDocument::query()->find($document->id))->status->toBe(FiscalStatus::Authorized);
});

test('transmitted documents cannot be deleted; only untransmitted drafts can', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $draft = fiscalDraft();
    $issued = fiscalDraft();
    $this->post("/fiscal/documents/{$issued->id}/issue");

    $this->delete("/fiscal/documents/{$issued->id}")->assertSessionHasErrors('document');
    $this->delete("/fiscal/documents/{$draft->id}")->assertRedirect('/fiscal/documents');

    expect(FiscalDocument::query()->pluck('id')->all())->toBe([$issued->id]);
});

test('settings never expose credentials, and changing tax data requires a new confirmation', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);

    $this->get('/fiscal/settings')->assertOk()->assertDontSee('chave-empresa-teste')->assertDontSee('csc-teste')->assertDontSee('chave-titular-teste')
        ->assertInertia(fn ($page) => $page->where('settings.has_nfce_csc', true)->missing('settings.nfce_csc')->missing('settings.provider_credentials'));

    $setting = FiscalSetting::query()->sole();
    $this->post('/fiscal/settings', [
        'legal_name' => $setting->legal_name, 'cnpj' => '11.222.333/0001-81', 'address' => $setting->address, 'city_code' => '3550308',
        'tax_regime' => 'regimeNormal', 'environment' => 'homologation', 'enabled_types' => ['nfse'], 'nfse' => $setting->nfse, 'product_defaults' => $setting->product_defaults,
    ])->assertSessionHasNoErrors();

    expect($setting->refresh())->tax_settings_confirmed_at->toBeNull()->nfce_csc->toBe('csc-teste')->enabled_types->toBe(['nfse']);
});

test('registering the issuer stores the company key encrypted, and the certificate goes straight to the provider', function () {
    $spedy = fiscalReady($company = Company::factory()->create(), ['provider_company_id' => null, 'provider_credentials' => null, 'registration_status' => 'pending', 'certificate_expires_at' => null]);
    actingAsFiscalMember($company);

    $this->post('/fiscal/settings/register')->assertSessionHasNoErrors();
    $setting = FiscalSetting::query()->sole();
    expect($setting->isRegistered())->toBeTrue()->and($setting->apiKey())->toStartWith('chave-empresa-simulada-');
    expect(DB::table('fiscal_settings')->value('provider_credentials'))->not->toContain('chave-empresa-simulada-');

    $file = UploadedFile::fake()->createWithContent('empresa.pfx', 'conteudo-pfx-ficticio');
    $this->post('/fiscal/settings/certificate', ['certificate' => $file, 'password' => 'senha-ficticia'])->assertSessionHasNoErrors();

    expect($setting->refresh()->hasValidCertificate())->toBeTrue();
    expect($spedy->calls)->toContain("POST /companies/{$setting->provider_company_id}/certificates");
    expect(json_encode(DB::table('fiscal_settings')->first()))->not->toContain('senha-ficticia')->not->toContain('conteudo-pfx-ficticio');
    expect(Storage::disk('fiscal')->allFiles())->toBe([]);
});

test('root admin sets the platform key write-only and releases production per company', function () {
    $admin = User::factory()->create(['is_root_admin' => true]);
    fiscalReady($company = Company::factory()->create());
    $this->actingAs($admin);
    $provider = FiscalProviderAvailability::query()->sole();

    $this->get('/rootadmin/fiscal')->assertOk()->assertDontSee('chave-titular-teste')->assertInertia(fn ($page) => $page->where('providers.0.has_owner_key', true));
    $this->patch("/rootadmin/fiscal/providers/{$provider->id}", ['is_available' => true, 'document_types' => ['nfse'], 'environment' => 'production'])->assertSessionHasErrors('owner_api_key');
    $this->patch("/rootadmin/fiscal/providers/{$provider->id}", ['is_available' => true, 'document_types' => ['nfse'], 'environment' => 'sandbox'])->assertSessionHasNoErrors();
    expect($provider->refresh())->document_types->toBe(['nfse'])->and($provider->ownerKey())->toBe('chave-titular-teste');

    $setting = FiscalSetting::query()->sole();
    $this->patch("/rootadmin/fiscal/companies/{$setting->id}/release", ['released' => true])->assertSessionHasNoErrors();
    expect($setting->refresh()->production_released_at)->not->toBeNull();
});

test('the fiscal pages render', function (string $url, string $component) {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();

    $this->get(str_replace('{document}', (string) $document->id, $url))->assertOk()->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/fiscal', 'fiscal/dashboard'],
    ['/fiscal/documents', 'fiscal/documents/index'],
    ['/fiscal/documents/create', 'fiscal/documents/form'],
    ['/fiscal/documents/{document}/edit', 'fiscal/documents/form'],
    ['/fiscal/items', 'fiscal/items'],
    ['/fiscal/reports', 'fiscal/reports'],
    ['/fiscal/settings', 'fiscal/settings'],
]);

test('documents export to CSV', function () {
    fiscalReady($company = Company::factory()->create());
    actingAsFiscalMember($company);
    $document = fiscalDraft();
    $this->post("/fiscal/documents/{$document->id}/issue");

    expect($this->get('/fiscal/documents/export')->assertOk()->streamedContent())->toStartWith("\u{FEFF}")->toContain('NFS-e')->toContain('Autorizado');
    expect($this->get('/fiscal/reports/csv')->assertOk()->streamedContent())->toContain('Autorizado');
});
