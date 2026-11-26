<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Parties\Models\Party;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Services\QuickSaleService;
use App\Modules\Sales\Services\SaleCustomers;
use App\Modules\Sales\Services\SaleFulfillmentService;
use App\Modules\Sales\Services\SaleItems;
use App\Modules\Sales\Services\SaleOrderService;
use App\Modules\Sales\Services\SaleQuoteService;
use App\Modules\Sales\Services\SaleReturnService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Cenários de vendas gerados pelos serviços reais, em duas combinações de módulos: a empresa de demonstração
 * (Vendas com Produtos e estoque) e uma loja que contrata somente Vendas. As chaves de idempotência impedem
 * duplicidade quando o seed é repetido.
 */
class SalesDemoSeeder extends Seeder
{
    private Company $company;

    private User $user;

    public function run(): void
    {
        $company = Company::query()->where('slug', 'empresa-demonstracao')->first();
        $user = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $company === null || $user === null) {
            $this->command?->warn('SalesDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após PurchaseDemoSeeder.');

            return;
        }

        $this->user = $user;
        $this->withStock($company);
        $this->salesOnly();
    }

    private function withStock(Company $company): void
    {
        $this->company = $company;
        $this->activate('sales');
        $notebook = $this->variant('Caderno universitário');
        $pen = $this->variant('Caneta esferográfica');
        $binding = $this->variant('Encadernação');
        $shirt = $this->variant('Camiseta básica', 'Branca / M');
        $ana = Party::query()->customersOf($company)->where('document', '52998224725')->first();
        $horizonte = Party::query()->customersOf($company)->where('document', '11222333000181')->first();

        if (in_array(null, [$notebook, $pen, $binding, $shirt, $ana, $horizonte], true)) {
            $this->command?->warn('SalesDemoSeeder: execute CustomerDemoSeeder, ProductDemoSeeder e PurchaseDemoSeeder antes.');

            return;
        }

        $quotes = app(SaleQuoteService::class);
        $today = now($company->timezone)->format('Y-m-d');
        $this->quote('demo-quote-pending', $horizonte, [[$notebook, '50', '19.90'], [$pen, '100', '2.50']], issue: true);
        $converted = $this->quote('demo-quote-converted', $ana, [[$shirt, '2', '39.90']], issue: true);

        if ($converted->status === SaleQuoteStatus::Issued) {
            $quotes->approve($converted, $this->user, $today);
            $quotes->convert($converted->refresh(), $this->user);
        }

        $direct = $this->order('demo-sale-direct', $horizonte, [[$notebook, '5', '19.90']], ['payment_condition' => 'term', 'payment_method' => 'bank_slip', 'installments' => [['due_date' => now()->addDays(28)->format('Y-m-d'), 'amount' => '99.50']]]);
        $this->confirm($direct);
        $service = $this->order('demo-sale-service', $ana, [[$binding, '3', '15.00']], ['delivery_method' => 'service', 'payment_condition' => 'cash', 'payment_method' => 'pix']);
        $this->fulfill($this->confirm($service), 'demo-fulfillment-service', [$binding->id => '3']);
        $partial = $this->order('demo-sale-partial', $horizonte, [[$notebook, '4', '19.90'], [$pen, '20', '2.50']], ['delivery_method' => 'delivery', 'delivery_address_text' => 'Av. Paulista, 1000 · São Paulo/SP']);
        $this->fulfill($this->confirm($partial), 'demo-fulfillment-partial', [$notebook->id => '2', $pen->id => '20']);
        $completed = $this->order('demo-sale-completed', $ana, [[$pen, '10', '2.50'], [$notebook, '2', '19.90']], ['payment_condition' => 'cash', 'payment_method' => 'credit_card']);
        $fulfillment = $this->fulfill($this->confirm($completed), 'demo-fulfillment-completed', [$pen->id => '10', $notebook->id => '2']);

        if ($completed->refresh()->status === SaleOrderStatus::Fulfilled) {
            app(SaleOrderService::class)->complete($completed, $this->user);
        }

        if (! SaleReturn::query()->where('company_id', $company->id)->where('idempotency_key', 'demo-return-partial')->exists()) {
            app(SaleReturnService::class)->register($fulfillment, $this->user, [
                'returned_at' => $today,
                'reason' => 'Cliente trocou de ideia sobre parte das canetas.',
                'restock' => true,
                'idempotency_key' => 'demo-return-partial',
                'items' => [['sale_fulfillment_item_id' => $fulfillment->items()->where('product_variant_id', $pen->id)->sole()->id, 'quantity' => '4']],
            ]);
        }

        $cancelled = $this->order('demo-sale-cancelled', null, [[$shirt, '1', '39.90']], []);

        if ($cancelled->status === SaleOrderStatus::Draft) {
            app(SaleOrderService::class)->cancel($cancelled, $this->user, 'Cliente desistiu da compra.');
        }
    }

    /** Loja que contrata somente Vendas: cliente e item simplificados, sem estoque. */
    private function salesOnly(): void
    {
        $this->company = Company::query()->firstOrCreate(['slug' => 'loja-somente-vendas'], ['name' => 'Loja Somente Vendas', 'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => 'BRL']);

        if (! $this->company->users()->whereKey($this->user->id)->exists()) {
            $this->company->users()->attach($this->user, ['role' => 'owner', 'is_active' => true, 'permissions' => []]);
        }

        $this->activate('sales');

        if (SaleOrder::query()->where('company_id', $this->company->id)->where('idempotency_key', 'demo-sales-only-quick')->exists()) {
            return;
        }

        $customer = app(SaleCustomers::class)->createSimplified($this->company, $this->user, ['type' => 'individual', 'name' => 'Cliente de Balcão', 'phone' => '(11) 97777-6666']);
        $item = app(SaleItems::class)->createSimplified($this->company, $this->user, ['name' => 'Arranjo de flores', 'type' => 'simple', 'sale_price' => '89.90']);
        app(QuickSaleService::class)->register($this->company, $this->user, [
            'customer_party_id' => $customer->id,
            'order_date' => now($this->company->timezone)->format('Y-m-d'),
            'delivery_method' => 'pickup',
            'payment_condition' => 'cash',
            'payment_method' => 'pix',
            'items' => [['product_variant_id' => $item->id, 'quantity' => '1']],
            'idempotency_key' => 'demo-sales-only-quick',
        ], SaleAbilities::full());
    }

    private function activate(string $identifier): void
    {
        $module = PlatformModule::query()->where('identifier', $identifier)->firstOrFail();
        CompanyModule::query()->firstOrCreate(['company_id' => $this->company->id, 'platform_module_id' => $module->id], ['activated_by' => $this->user->id, 'activated_at' => now()]);
    }

    private function variant(string $product, ?string $label = null): ?ProductVariant
    {
        return ProductVariant::query()->where('company_id', $this->company->id)->where('label', $label)
            ->whereHas('product', fn (Builder $query) => $query->where('name', $product))->first();
    }

    /** @param list<array{ProductVariant, string, string}> $lines */
    private function quote(string $key, Party $customer, array $lines, bool $issue): SaleQuote
    {
        $existing = SaleQuote::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $quotes = app(SaleQuoteService::class);
        $quote = $quotes->create($this->company, $this->user, [
            'customer_party_id' => $customer->id,
            'issue_date' => now($this->company->timezone)->format('Y-m-d'),
            'valid_until' => now($this->company->timezone)->addDays(15)->format('Y-m-d'),
            'payment_condition' => 'undefined',
            'commercial_terms' => 'Entrega em até 5 dias úteis após a aprovação.',
            'items' => $this->items($lines),
            'idempotency_key' => $key,
        ], SaleAbilities::full());

        return $issue ? $quotes->issue($quote, $this->user, now($this->company->timezone)->format('Y-m-d')) : $quote;
    }

    /**
     * @param  list<array{ProductVariant, string, string}>  $lines
     * @param  array<string, mixed>  $data
     */
    private function order(string $key, ?Party $customer, array $lines, array $data): SaleOrder
    {
        // As datas são relativas ao dia do seed; um documento já criado nunca é regerado.
        return SaleOrder::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->first()
            ?? app(SaleOrderService::class)->create($this->company, $this->user, [
                'customer_party_id' => $customer?->id,
                'order_date' => now($this->company->timezone)->format('Y-m-d'),
                'delivery_method' => 'pickup',
                'payment_condition' => 'undefined',
                ...$data,
                'items' => $this->items($lines),
                'idempotency_key' => $key,
            ], SaleAbilities::full());
    }

    private function confirm(SaleOrder $order): SaleOrder
    {
        return $order->status === SaleOrderStatus::Draft ? app(SaleOrderService::class)->confirm($order, $this->user) : $order;
    }

    /** @param array<int, string> $quantities Quantidade por unidade comercializável. */
    private function fulfill(SaleOrder $order, string $key, array $quantities): SaleFulfillment
    {
        return SaleFulfillment::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->first()
            ?? app(SaleFulfillmentService::class)->fulfill($order, $this->user, [
                'fulfilled_at' => now($this->company->timezone)->format('Y-m-d'),
                'method' => $order->delivery_method->value,
                'notes' => null,
                'idempotency_key' => $key,
                'items' => $order->items()->get()->map(fn ($item): array => ['sale_order_item_id' => $item->id, 'quantity' => $quantities[$item->product_variant_id] ?? '0'])->all(),
            ]);
    }

    /**
     * @param  list<array{ProductVariant, string, string}>  $lines
     * @return list<array<string, string|int>>
     */
    private function items(array $lines): array
    {
        return array_map(fn (array $line): array => ['product_variant_id' => $line[0]->id, 'quantity' => $line[1], 'unit_price' => $line[2]], $lines);
    }
}
