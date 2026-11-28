<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Finance\Services\FinanceTitleService;
use App\Modules\Fiscal\Enums\FiscalOrigin;
use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalProviderAvailability;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Services\FiscalDocumentService;
use App\Modules\Fiscal\Services\FiscalOrigins;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosCheckout;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Pos\Services\PosTerminalService;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Services\QuickSaleService;
use App\Modules\Sales\Services\SaleItems;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\Support\SimulatedSpedy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Cenários de Documentos Fiscais com a Spedy **simulada** (nenhuma requisição sai do processo; nenhum documento real):
 * - "Agência Somente Fiscal": NFS-e avulsas autorizada, rejeitada, cancelada e um rascunho;
 * - "Loja Fiscal + Vendas": NF-e de uma venda confirmada;
 * - "Padaria Fiscal + PDV": NFC-e de um atendimento finalizado, distinta do comprovante não fiscal;
 * - "Consultoria Fiscal + Financeiro": NFS-e de uma conta a receber, sem baixa.
 *
 * Todos em homologação, com CNPJs, chaves e números fictícios e marcados como demonstração. Restrito a local e
 * testing; repetir não duplica (cada empresa só é gerada uma vez). Torna a Spedy disponível somente nestes ambientes,
 * com chave de plataforma fictícia.
 */
class FiscalDemoSeeder extends Seeder
{
    private Company $company;

    private User $user;

    private SimulatedSpedy $spedy;

    public function run(): void
    {
        $user = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $user === null) {
            $this->command?->warn('FiscalDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após CustomerDemoSeeder.');

            return;
        }

        $this->user = $user;
        // A transmissão roda na fila; na demonstração ela acontece no próprio processo, contra o provedor simulado.
        config(['queue.default' => 'sync']);
        $this->spedy = (new SimulatedSpedy)->start();
        FiscalProviderAvailability::query()->updateOrCreate(['identifier' => 'spedy'], [
            'name' => 'Spedy', 'is_available' => true, 'document_types' => ['nfse', 'nfe', 'nfce'], 'environment' => 'sandbox',
            'platform_credentials' => ['owner_api_key' => 'chave-de-demonstracao', 'webhook_secret' => 'whsec_'.base64_encode('segredo-de-demonstracao-32bytes')],
        ]);

        $this->fiscalOnly();
        $this->withSales();
        $this->withPos();
        $this->withFinance();
    }

    private function fiscalOnly(): void
    {
        if (! $this->begin('agencia-somente-fiscal', 'Agência Somente Fiscal', [], '00000000000191')) {
            return;
        }

        $authorized = $this->draft(['description' => 'Criação de identidade visual — demonstração', 'unit_price' => '1800.00', 'receiver' => ['name' => 'Cliente Demonstração Ltda', 'document' => '11222333000181']]);
        $this->issue($authorized);

        $rejected = $this->draft(['description' => 'Gestão de redes sociais — novembro', 'unit_price' => '950.00', 'receiver' => ['name' => 'Tomador Exemplo', 'document' => '52998224725']]);
        $this->spedy->nextOutcome = 'rejected';
        $this->issue($rejected);

        $cancelled = $this->draft(['description' => 'Sessão de fotos — demonstração', 'unit_price' => '400.00', 'receiver' => ['name' => 'Tomador Exemplo', 'document' => '52998224725']]);
        $this->issue($cancelled);
        app(FiscalDocumentService::class)->cancel($cancelled->refresh(), $this->user, 'Serviço não realizado: cliente desistiu da sessão.');

        $this->draft(['description' => 'Consultoria de marca (rascunho)', 'unit_price' => '600.00', 'receiver' => ['name' => 'Cliente em negociação']]);
    }

    private function withSales(): void
    {
        if (! $this->begin('loja-fiscal-vendas', 'Loja Fiscal + Vendas', ['sales'], '00000000000272')) {
            return;
        }

        $item = app(SaleItems::class)->createSimplified($this->company, $this->user, ['name' => 'Caneca de cerâmica', 'type' => 'simple', 'sale_price' => '39.90']);
        $item->product->forceFill(['ncm' => '69120000', 'merchandise_origin' => '0'])->save();
        $order = app(QuickSaleService::class)->register($this->company, $this->user, [
            'order_date' => now($this->company->timezone)->format('Y-m-d'),
            'delivery_method' => 'pickup',
            'payment_condition' => 'cash',
            'payment_method' => 'pix',
            'items' => [['product_variant_id' => $item->id, 'quantity' => '2']],
            'idempotency_key' => 'demo-fiscal-sale-1',
        ], SaleAbilities::full());
        $document = app(FiscalDocumentService::class)->createDraft($this->company, $this->user, [
            'type' => 'nfe', 'origin' => FiscalOrigin::Sale->value, 'origin_id' => $order->id,
            'receiver' => ['name' => 'Comprador Demonstração Ltda', 'document' => '11222333000181', 'address' => ['postal_code' => '01001000', 'street' => 'Praça da Sé', 'number' => '100', 'district' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP']],
            'idempotency_key' => 'demo-fiscal-sale-document-1',
        ]);
        $this->issue($document);
    }

    private function withPos(): void
    {
        if (! $this->begin('padaria-fiscal-pdv', 'Padaria Fiscal + PDV', ['pos'], '00000000000353')) {
            return;
        }

        $terminal = app(PosTerminalService::class)->save($this->company, $this->user, new PosTerminal, ['name' => 'Caixa 01', 'code' => 'CX01', 'is_active' => true, 'operators' => []]);
        $session = app(PosSessionService::class)->open($this->company, $this->user, $terminal, '50.00', null, 'demo-fiscal-pos-open');
        $sale = app(PosCheckout::class)->finalize($session, $this->user, [
            'items' => [['description' => 'Pão francês (kg)', 'unit_price' => '14.90', 'quantity' => '1']],
            'payments' => [['method' => 'cash', 'amount' => '14.90']],
            'idempotency_key' => 'demo-fiscal-pos-sale-1',
        ]);
        $draft = app(FiscalOrigins::class)->resolve($this->company, FiscalOrigin::Pos, $sale->id);
        $document = app(FiscalDocumentService::class)->createDraft($this->company, $this->user, [
            'type' => 'nfce', 'origin' => FiscalOrigin::Pos->value, 'origin_id' => $sale->id, 'payment_method' => 'cash',
            'items' => array_map(fn (array $item): array => [...$item, 'ncm' => '19059090'], $draft['items']),
            'idempotency_key' => 'demo-fiscal-pos-document-1',
        ]);
        $this->issue($document);
    }

    private function withFinance(): void
    {
        if (! $this->begin('consultoria-fiscal-financeiro', 'Consultoria Fiscal + Financeiro', ['finance'], '00000000000434')) {
            return;
        }

        $title = app(FinanceTitleService::class)->create($this->company, $this->user, [
            'kind' => 'receivable', 'description' => 'Consultoria tributária — novembro', 'amount' => '2500.00',
            'competence_date' => now($this->company->timezone)->format('Y-m-d'), 'due_date' => now($this->company->timezone)->addDays(10)->format('Y-m-d'),
            'idempotency_key' => 'demo-fiscal-title-1',
        ])->first();
        $document = app(FiscalDocumentService::class)->createDraft($this->company, $this->user, [
            'type' => 'nfse', 'origin' => FiscalOrigin::Finance->value, 'origin_id' => $title->id,
            'receiver' => ['name' => 'Cliente Demonstração Ltda', 'document' => '11222333000181'],
            'idempotency_key' => 'demo-fiscal-title-document-1',
        ]);
        $this->issue($document);
    }

    /**
     * Empresa de demonstração já configurada para homologação: emissora cadastrada no provedor simulado, certificado
     * fictício vigente e padrões confirmados (valores de exemplo, não orientação tributária).
     *
     * @param  list<string>  $modules
     */
    private function begin(string $slug, string $name, array $modules, string $cnpj): bool
    {
        $this->company = Company::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => 'BRL']);

        if (! $this->company->users()->whereKey($this->user->id)->exists()) {
            $this->company->users()->attach($this->user, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
        }

        foreach (['fiscal', ...$modules] as $identifier) {
            $module = PlatformModule::query()->where('identifier', $identifier)->firstOrFail();
            CompanyModule::query()->firstOrCreate(['company_id' => $this->company->id, 'platform_module_id' => $module->id], ['activated_by' => $this->user->id, 'activated_at' => now()]);
        }

        if (FiscalDocument::query()->where('company_id', $this->company->id)->exists()) {
            return false;
        }

        $this->spedy->issuerCnpj = $cnpj;
        FiscalSetting::query()->updateOrCreate(['company_id' => $this->company->id], [
            'legal_name' => "{$name} (demonstração) LTDA", 'trade_name' => $name, 'cnpj' => $cnpj,
            'email' => 'fiscal@demonstracao.vetorflex.test',
            'address' => ['street' => 'Rua de Demonstração', 'number' => '1', 'district' => 'Centro', 'postal_code' => '01001000', 'city' => 'São Paulo', 'state' => 'SP'],
            'city_code' => '3550308', 'tax_regime' => 'simplesNacional', 'provider' => 'spedy', 'environment' => 'homologation',
            'enabled_types' => ['nfse', 'nfe', 'nfce'], 'series' => ['nfse' => '1', 'nfe' => '1', 'nfce' => '1'],
            'provider_company_id' => 'empresa-demonstracao-'.Str::lower(Str::random(8)), 'provider_credentials' => ['api_key' => 'chave-empresa-demonstracao'], 'provider_environment' => 'sandbox',
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED, 'registered_at' => now(),
            'certificate_subject' => 'CN=CERTIFICADO FICTICIO DE DEMONSTRACAO', 'certificate_expires_at' => now()->addYear(),
            'nfse' => ['service_code' => '17.01', 'iss_rate' => '2', 'taxation_type' => 'taxationInMunicipality', 'issue_type' => 'annfs'],
            'product_defaults' => ['unit' => 'UN', 'origin' => '0', 'cfop' => '5102', 'icms_situation' => '102', 'pis_situation' => '49', 'cofins_situation' => '49'],
            'nfce_csc_id' => '1', 'nfce_csc' => 'csc-de-demonstracao',
            'tax_settings_confirmed_at' => now(), 'tax_settings_confirmed_by' => 'Demonstração',
        ]);

        return true;
    }

    /** @param array{description: string, unit_price: string, receiver: array<string, string>} $data */
    private function draft(array $data): FiscalDocument
    {
        return app(FiscalDocumentService::class)->createDraft($this->company, $this->user, [
            'type' => 'nfse',
            'receiver' => $data['receiver'],
            'items' => [['description' => $data['description'], 'quantity' => '1', 'unit_price' => $data['unit_price']]],
            'description' => $data['description'],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function issue(FiscalDocument $document): void
    {
        app(FiscalDocumentService::class)->issue($document, $this->user);
    }
}
