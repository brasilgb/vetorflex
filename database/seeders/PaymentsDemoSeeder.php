<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Finance\Services\FinanceAccountService;
use App\Modules\Finance\Services\FinanceTitleService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Payments\Enums\AccountStatus;
use App\Modules\Payments\Enums\WebhookStatus;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentProviderAccount;
use App\Modules\Payments\Models\PaymentProviderAvailability;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosCheckout;
use App\Modules\Pos\Services\PosPix;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Pos\Services\PosTerminalService;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Services\QuickSaleService;
use App\Modules\Sales\Services\SaleItems;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\Support\SimulatedEfi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Cenários de Pagamentos com o provedor Efí **simulado** (nenhuma requisição sai do processo, nenhuma cobrança real):
 * - "Estúdio Somente Pagamentos": cobranças avulsas paga, pendente e cancelada;
 * - "Loja Pagamentos + Vendas": cobrança de uma venda, paga;
 * - "Consultoria Pagamentos + Financeiro": cobrança de conta a receber paga e baixada no Financeiro;
 * - "Café Pagamentos + PDV": Pix dinâmico confirmado no caixa.
 *
 * As contas ficam em homologação com credenciais fictícias. Restrito a local e testing; repetir não duplica (cada
 * empresa só é gerada uma vez). Torna o provedor Efí disponível somente nestes ambientes.
 */
class PaymentsDemoSeeder extends Seeder
{
    private Company $company;

    private User $user;

    private SimulatedEfi $efi;

    public function run(): void
    {
        $user = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $user === null) {
            $this->command?->warn('PaymentsDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após CustomerDemoSeeder.');

            return;
        }

        $this->user = $user;
        $this->efi = new SimulatedEfi;
        $this->efi->start();
        PaymentProviderAvailability::query()->updateOrCreate(['identifier' => 'efi'], ['name' => 'Efí', 'is_available' => true]);

        $this->paymentsOnly();
        $this->withSales();
        $this->withFinance();
        $this->withPos();
    }

    private function paymentsOnly(): void
    {
        if (! $this->begin('estudio-somente-pagamentos', 'Estúdio Somente Pagamentos', [])) {
            return;
        }

        $this->account();
        $paid = $this->charge(['description' => 'Aula experimental de pilates', 'amount' => '80.00', 'payer' => ['name' => 'Juliana Demonstração', 'document' => '529.982.247-25']], 'paid');
        $this->charge(['description' => 'Pacote mensal — dezembro', 'amount' => '320.00', 'payer' => ['name' => 'Ricardo Exemplo']], 'pending');
        $cancelled = $this->charge(['description' => 'Avaliação física', 'amount' => '60.00'], 'pending');
        app(ChargeService::class)->cancel($cancelled, $this->user, 'Cliente remarcou para outro mês.');
        $this->webhookEvent($paid);
    }

    private function withSales(): void
    {
        if (! $this->begin('loja-pagamentos-vendas', 'Loja Pagamentos + Vendas', ['sales'])) {
            return;
        }

        $this->account();
        $item = app(SaleItems::class)->createSimplified($this->company, $this->user, ['name' => 'Cesta de café da manhã', 'type' => 'simple', 'sale_price' => '149.90']);
        $order = app(QuickSaleService::class)->register($this->company, $this->user, [
            'order_date' => now($this->company->timezone)->format('Y-m-d'),
            'delivery_method' => 'delivery',
            'delivery_address_text' => 'Rua das Flores, 120 — Centro (endereço fictício de demonstração)',
            'payment_condition' => 'cash',
            'payment_method' => 'pix',
            'items' => [['product_variant_id' => $item->id, 'quantity' => '1']],
            'idempotency_key' => 'demo-payments-sale-1',
        ], SaleAbilities::full());
        $this->charge(['origin' => 'sale', 'origin_id' => $order->id], 'paid');
    }

    private function withFinance(): void
    {
        if (! $this->begin('consultoria-pagamentos-financeiro', 'Consultoria Pagamentos + Financeiro', ['finance'])) {
            return;
        }

        $bank = app(FinanceAccountService::class)->create($this->company, $this->user, ['name' => 'Conta Efí', 'type' => 'digital', 'institution' => 'Efí (demonstração)', 'opening_balance' => '0.00', 'opening_date' => now($this->company->timezone)->subMonth()->format('Y-m-d'), 'notes' => null]);
        $this->account($bank->id);
        $titles = app(FinanceTitleService::class)->create($this->company, $this->user, [
            'kind' => 'receivable', 'description' => 'Consultoria de processos — novembro', 'amount' => '1200.00',
            'competence_date' => now($this->company->timezone)->format('Y-m-d'), 'due_date' => now($this->company->timezone)->addDays(5)->format('Y-m-d'),
            'idempotency_key' => 'demo-payments-title-1',
        ]);
        $this->charge(['origin' => 'finance', 'origin_id' => $titles->first()->id], 'paid');
    }

    private function withPos(): void
    {
        if (! $this->begin('cafe-pagamentos-pdv', 'Café Pagamentos + PDV', ['pos'])) {
            return;
        }

        $this->account();
        $terminal = app(PosTerminalService::class)->save($this->company, $this->user, new PosTerminal, ['name' => 'Caixa 01', 'code' => 'CX01', 'is_active' => true, 'operators' => []]);
        $session = app(PosSessionService::class)->open($this->company, $this->user, $terminal, '50.00', null, 'demo-payments-pos-open');
        $cartKey = (string) Str::uuid();
        $charge = app(PosPix::class)->create($session, $this->user, '18.50', $cartKey, (string) Str::uuid());
        $this->efi->pay((string) $charge->provider_charge_id, '18.50', 'E'.Str::upper(Str::random(31)));
        app(ChargeService::class)->sync($charge, $this->user);
        app(PosCheckout::class)->finalize($session, $this->user, [
            'items' => [['description' => 'Cappuccino e pão de queijo', 'unit_price' => '18.50', 'quantity' => '1']],
            'payments' => [['method' => 'pix', 'amount' => '18.50', 'payment_charge_id' => $charge->id]],
            'idempotency_key' => 'demo-payments-pos-sale-1',
        ]);
    }

    /** @param list<string> $modules */
    private function begin(string $slug, string $name, array $modules): bool
    {
        $this->company = Company::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => 'BRL']);

        if (! $this->company->users()->whereKey($this->user->id)->exists()) {
            $this->company->users()->attach($this->user, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
        }

        foreach (['payments', ...$modules] as $identifier) {
            $module = PlatformModule::query()->where('identifier', $identifier)->firstOrFail();
            CompanyModule::query()->firstOrCreate(['company_id' => $this->company->id, 'platform_module_id' => $module->id], ['activated_by' => $this->user->id, 'activated_at' => now()]);
        }

        return ! PaymentCharge::query()->where('company_id', $this->company->id)->exists();
    }

    private function account(?int $financeAccountId = null): PaymentProviderAccount
    {
        $token = Str::random(48);

        return PaymentProviderAccount::query()->firstOrCreate(['company_id' => $this->company->id, 'provider' => 'efi', 'environment' => 'homologation'], [
            'name' => 'Efí — Homologação (demonstração)',
            'credentials' => ['client_id' => 'Client_Id_demonstracao', 'client_secret' => 'Client_Secret_demonstracao', 'certificate' => base64_encode("-----BEGIN CERTIFICATE-----\ndemonstracao\n-----END CERTIFICATE-----\n")],
            'pix_key' => 'demonstracao@vetorflex.test',
            'methods' => ['pix'],
            'expiration_seconds' => 3600,
            'webhook_token' => $token,
            'webhook_token_hash' => hash('sha256', $token),
            'status' => AccountStatus::Active,
            'last_tested_at' => now(),
            'finance_account_id' => $financeAccountId,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function charge(array $data, string $outcome): PaymentCharge
    {
        $charges = app(ChargeService::class);
        $charge = $charges->create($this->company, $this->user, [
            'due_date' => now($this->company->timezone)->addDays(3)->format('Y-m-d'),
            'idempotency_key' => (string) Str::uuid(),
            ...$data,
        ]);

        if ($outcome === 'paid') {
            $this->efi->pay((string) $charge->provider_charge_id, (string) $charge->amount, 'E'.Str::upper(Str::random(31)));
            $charge = $charges->sync($charge, $this->user);
        }

        return $charge;
    }

    /** Registro de notificação simulada já processada, para a tela de conciliação. */
    private function webhookEvent(PaymentCharge $charge): void
    {
        PaymentWebhookEvent::query()->firstOrCreate(['payment_provider_account_id' => $charge->payment_provider_account_id, 'payload_hash' => hash('sha256', "demo:{$charge->id}")], [
            'company_id' => $charge->company_id,
            'provider' => 'efi',
            'payload' => ['pix' => [['txid' => $charge->provider_charge_id, 'demonstracao' => true]]],
            'status' => WebhookStatus::Processed,
            'attempts' => 1,
            'processed_at' => now(),
        ]);
    }
}
