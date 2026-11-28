<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Services\FinanceAccountService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Pos\Data\PosSettings;
use App\Modules\Pos\Enums\CashMovementType;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosCashService;
use App\Modules\Pos\Services\PosCatalog;
use App\Modules\Pos\Services\PosCheckout;
use App\Modules\Pos\Services\PosModule;
use App\Modules\Pos\Services\PosReturnService;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Pos\Services\PosTerminalService;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductCatalog;
use App\Modules\Sales\Services\SaleItems;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Cenários do PDV gerados pelos serviços reais, em quatro combinações de módulos:
 * - "Mercadinho Somente PDV": itens avulsos, sem Produtos, Vendas ou Financeiro;
 * - "Papelaria PDV + Produtos": itens do catálogo com saída de estoque e reposição na devolução;
 * - "Lanchonete PDV + Vendas": as vendas do caixa aparecem em Vendas, sem duplicidade;
 * - "Quiosque PDV + Financeiro": formas de pagamento vinculadas a contas e sangrias como transferências.
 *
 * Cada empresa recebe dois terminais, um operador além do proprietário, uma sessão fechada (vendas, pagamento
 * combinado, suprimento, sangria e devolução) e uma sessão aberta com venda concluída e venda suspensa.
 * Restrito a local e testing. Repetir o seed não duplica: empresas com sessões de caixa não são reprocessadas.
 */
class PosDemoSeeder extends Seeder
{
    private Company $company;

    private User $owner;

    private User $operator;

    public function run(): void
    {
        $owner = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $owner === null) {
            $this->command?->warn('PosDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após CustomerDemoSeeder.');

            return;
        }

        $this->owner = $owner;
        $this->operator = User::query()->firstOrCreate(['email' => 'operador.pdv@vetornew.test'], [
            'name' => 'Operador de Caixa',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $this->scenario('mercadinho-somente-pdv', 'Mercadinho Somente PDV', []);
        $this->scenario('papelaria-pdv-produtos', 'Papelaria PDV + Produtos', ['products']);
        $this->scenario('lanchonete-pdv-vendas', 'Lanchonete PDV + Vendas', ['sales']);
        $this->scenario('quiosque-pdv-financeiro', 'Quiosque PDV + Financeiro', ['finance']);
    }

    /** @param list<string> $modules */
    private function scenario(string $slug, string $name, array $modules): void
    {
        $this->company = Company::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => 'BRL']);
        $this->member($this->owner, 'owner', []);
        $this->member($this->operator, 'operator', [PosPermission::Operate, PosPermission::OpenSession, PosPermission::CloseSession, PosPermission::Supply, PosPermission::Withdraw]);

        foreach (['pos', ...$modules] as $identifier) {
            $module = PlatformModule::query()->where('identifier', $identifier)->firstOrFail();
            CompanyModule::query()->firstOrCreate(['company_id' => $this->company->id, 'platform_module_id' => $module->id], ['activated_by' => $this->owner->id, 'activated_at' => now()]);
        }

        if (PosSession::query()->where('company_id', $this->company->id)->exists()) {
            return;
        }

        if (in_array('finance', $modules, true)) {
            $this->financeSettings();
        }

        $terminals = app(PosTerminalService::class);
        $register = $terminals->save($this->company, $this->owner, new PosTerminal, ['name' => 'Caixa 01', 'code' => 'CX01', 'location' => 'Entrada da loja', 'is_active' => true, 'receipt_width' => 80, 'operators' => [$this->owner->id, $this->operator->id]]);
        $counter = $terminals->save($this->company, $this->owner, new PosTerminal, ['name' => 'Balcão', 'code' => 'BALCAO', 'location' => null, 'is_active' => true, 'receipt_width' => 58, 'operators' => []]);
        $items = in_array('products', $modules, true) ? $this->catalogItems() : $this->customItems();

        $this->closedSession($register, $items);
        $this->openSession($counter, $items);
    }

    /**
     * Sessão do proprietário, já fechada com pequena falta de dinheiro justificada.
     *
     * @param  array{list<array<string, mixed>>, list<array<string, mixed>>}  $items
     */
    private function closedSession(PosTerminal $terminal, array $items): void
    {
        $sessions = app(PosSessionService::class);
        $cash = app(PosCashService::class);
        $session = $sessions->open($this->company, $this->owner, $terminal, '150.00', 'Troco inicial do dia.', "demo-pos-{$this->company->id}-open-1");

        $combined = $this->sell($session, $this->owner, $items[0], fn (string $total): array => [
            ['method' => PaymentMethod::Pix->value, 'amount' => '10.00'],
            ['method' => PaymentMethod::Cash->value, 'amount' => (string) BigDecimal::of($total)->minus('10.00')->plus('5.00')],
        ], 'sale-1');
        $this->sell($session, $this->owner, $items[1], fn (string $total): array => [['method' => PaymentMethod::DebitCard->value, 'amount' => $total]], 'sale-2');
        $this->sell($session, $this->owner, $items[1], fn (string $total): array => [['method' => PaymentMethod::Cash->value, 'amount' => $total]], 'sale-3');

        $cash->record($session, $this->owner, CashMovementType::Supply, '50.00', 'Reforço de troco', null, $this->key('supply-1'));
        $cash->record($session, $this->owner, CashMovementType::Withdrawal, '100.00', 'Recolhimento ao cofre', null, $this->key('withdrawal-1'));

        $item = $combined->order()->firstOrFail()->items()->orderBy('id')->firstOrFail();
        app(PosReturnService::class)->register($combined->refresh(), $this->owner, $session, [
            'items' => [['sale_order_item_id' => $item->id, 'quantity' => '1']],
            'restock' => true,
            'reason' => 'Cliente desistiu de uma unidade.',
            'refund_method' => PaymentMethod::Cash->value,
            'idempotency_key' => $this->key('return-1'),
        ]);

        $expected = $sessions->summary($session->refresh())['expected_cash'];
        $sessions->close($session, $this->owner, (string) BigDecimal::of($expected)->minus('2.00'), 'Diferença de troco na contagem.', null);
    }

    /**
     * Sessão do operador ainda aberta, com uma venda concluída e uma suspensa.
     *
     * @param  array{list<array<string, mixed>>, list<array<string, mixed>>}  $items
     */
    private function openSession(PosTerminal $terminal, array $items): void
    {
        $session = app(PosSessionService::class)->open($this->company, $this->operator, $terminal, '80.00', null, "demo-pos-{$this->company->id}-open-2");
        $this->sell($session, $this->operator, $items[1], fn (string $total): array => [['method' => PaymentMethod::Cash->value, 'amount' => (string) BigDecimal::of($total)->plus('10.00')]], 'sale-4');
        app(PosCheckout::class)->suspend($session, $this->operator, ['items' => $items[0], 'estimated_total' => null], 'Cliente foi buscar a carteira', $this->key('suspended-1'));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(string): list<array<string, mixed>>  $payments  Recebe o total previsto da venda.
     */
    private function sell(PosSession $session, User $operator, array $items, callable $payments, string $key): PosSale
    {
        $checkout = app(PosCheckout::class);
        $estimate = $this->estimate($items);

        return $checkout->finalize($session, $operator, ['items' => $items, 'payments' => $payments($estimate), 'idempotency_key' => $this->key($key)]);
    }

    /**
     * Itens avulsos (sem catálogo de Produtos): o primeiro grupo vai para o pagamento combinado.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function customItems(): array
    {
        return [
            [['description' => 'Leite integral 1 L', 'quantity' => '4', 'unit_price' => '5.49'], ['description' => 'Pão francês (kg)', 'unit_code' => 'KG', 'allows_fraction' => true, 'quantity' => '0.750', 'unit_price' => '16.00'], ['description' => 'Café 500 g', 'quantity' => '1', 'unit_price' => '26.04']],
            [['description' => 'Refrigerante 2 L', 'quantity' => '2', 'unit_price' => '9.90']],
        ];
    }

    /**
     * Itens do catálogo compartilhado com estoque inicial; a venda gera saída e a devolução, reposição.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function catalogItems(): array
    {
        $variant = function (string $name, string $price, string $stock, string $cost): int {
            $existing = ProductVariant::query()->where('company_id', $this->company->id)->whereHas('product', fn ($query) => $query->where('name', $name))->first();
            $variant = $existing ?? app(SaleItems::class)->createSimplified($this->company, $this->owner, ['name' => $name, 'type' => 'simple', 'sale_price' => $price]);
            app(InventoryService::class)->recordEntry(new StockOperation($this->company->id, $variant->id, $stock, user: $this->owner, reason: 'Estoque inicial de demonstração.', idempotencyKey: "demo-pos-stock-{$variant->id}", unitCost: $cost));

            return $variant->id;
        };
        $notebook = $variant('Caderno 10 matérias', '29.90', '40', '14.00');
        $pen = $variant('Caneta gel azul', '4.50', '200', '1.60');
        $glue = $variant('Cola bastão 40 g', '7.90', '60', '3.10');
        app(PosCatalog::class)->saveFavorites($this->company, $this->owner, [$pen, $notebook, $glue]);

        return [
            [['product_variant_id' => $notebook, 'quantity' => '2'], ['product_variant_id' => $pen, 'quantity' => '1']],
            [['product_variant_id' => $glue, 'quantity' => '2']],
        ];
    }

    /** Contas do caixa e do banco, com cada forma de pagamento vinculada à conta que recebe o valor. */
    private function financeSettings(): void
    {
        $account = fn (string $name, string $type): int => (FinanceAccount::query()->where('company_id', $this->company->id)->where('name', $name)->first()
            ?? app(FinanceAccountService::class)->create($this->company, $this->owner, ['name' => $name, 'type' => $type, 'institution' => null, 'opening_balance' => '0.00', 'opening_date' => now($this->company->timezone)->subDays(7)->format('Y-m-d'), 'notes' => null]))->id;
        $drawer = $account('Caixa do PDV', 'cash');
        $bank = $account('Conta corrente', 'checking');

        app(PosModule::class)->saveSettings($this->company, $this->owner, PosSettings::from([
            'payment_methods' => PosSettings::defaultMethods(),
            'finance_accounts' => ['cash' => $drawer, 'pix' => $bank, 'debit_card' => $bank, 'credit_card' => $bank],
            'cash_account_id' => $drawer,
            'treasury_account_id' => $bank,
        ]));
    }

    /**
     * Total previsto dos itens avulsos, para montar pagamentos exatos; o servidor recalcula na finalização.
     * Itens do catálogo usam o preço cadastrado, também conferido pelo servidor.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function estimate(array $items): string
    {
        $total = BigDecimal::zero();

        foreach ($items as $item) {
            $price = $item['unit_price'] ?? app(ProductCatalog::class)->pricesOf(ProductVariant::query()->findOrFail((int) $item['product_variant_id']))['effective'];
            $total = $total->plus(BigDecimal::of((string) $item['quantity'])->multipliedBy((string) $price));
        }

        return (string) $total->toScale(2, RoundingMode::HalfUp);
    }

    /** @param list<PosPermission> $permissions */
    private function member(User $user, string $role, array $permissions): void
    {
        if (! $this->company->users()->whereKey($user->id)->exists()) {
            $this->company->users()->attach($user, ['role' => $role, 'is_active' => true, 'permissions' => array_column($permissions, 'value')]);
        }
    }

    private function key(string $suffix): string
    {
        return "demo-pos-{$this->company->id}-{$suffix}";
    }
}
