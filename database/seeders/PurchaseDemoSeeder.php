<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseReceipt;
use App\Modules\Purchases\Models\PurchaseReturn;
use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Purchases\Services\PurchaseReceiptService;
use App\Modules\Purchases\Services\PurchaseReturnService;
use App\Modules\Purchases\Services\QuickPurchaseService;
use App\Modules\Purchases\Services\SupplierProductService;
use App\Modules\Purchases\Services\SupplierService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Cenários de compras gerados pelos serviços reais: estoque e custo médio mudam apenas por recebimentos e
 * devoluções. As chaves de idempotência impedem duplicidade quando o seed é repetido.
 */
class PurchaseDemoSeeder extends Seeder
{
    private Company $company;

    private User $user;

    public function run(): void
    {
        $company = Company::query()->where('slug', 'empresa-demonstracao')->first();
        $user = User::query()->where('email', 'demo@vetornew.test')->first();

        if (! app()->environment(['local', 'testing']) || $company === null || $user === null) {
            $this->command?->warn('PurchaseDemoSeeder ignorado: disponível apenas nos ambientes local e testing, após ProductDemoSeeder.');

            return;
        }

        $this->company = $company;
        $this->user = $user;
        $module = PlatformModule::query()->where('identifier', 'purchases')->firstOrFail();
        CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);

        $stationery = $this->supplier(['type' => 'company', 'name' => 'Papelaria Atacado Ltda.', 'trade_name' => 'Papelaria Atacado', 'document' => $this->cnpj('123456780001'), 'email' => 'vendas@papelaria.example.test', 'phone' => '(11) 3000-1000'], ['internal_code' => 'F-001', 'lead_time_days' => 5, 'payment_terms' => '30/60 dias', 'minimum_order' => '300.00']);
        $textile = $this->supplier(['type' => 'company', 'name' => 'Têxtil Algodão Brasil S.A.', 'document' => $this->cnpj('987654320001'), 'whatsapp' => '(47) 99999-0000'], ['internal_code' => 'F-002', 'lead_time_days' => 15]);
        // A cliente da demonstração também passa a ser fornecedora, sem duplicar o cadastro.
        $dualRole = $this->supplier(['type' => 'company', 'name' => 'Comercial Horizonte Ltda.', 'document' => '11222333000181'], ['internal_code' => 'F-003', 'payment_terms' => 'Boleto 28 dias']);
        $artisan = $this->supplier(['type' => 'individual', 'name' => 'João Artesão'], ['internal_code' => 'F-004']);

        $notebook = $this->variant('Caderno universitário');
        $pen = $this->variant('Caneta esferográfica');
        $fabric = $this->variant('Tecido de algodão');
        $wrapping = $this->variant('Papel para presente');
        $whiteShirt = $this->variant('Camiseta básica', 'Branca / M');
        $blackShirt = $this->variant('Camiseta básica', 'Preta / G');
        $binding = $this->variant('Encadernação');

        if (in_array(null, [$notebook, $pen, $fabric, $wrapping, $whiteShirt, $blackShirt, $binding], true)) {
            $this->command?->warn('PurchaseDemoSeeder ignorado: execute ProductDemoSeeder antes.');

            return;
        }

        $links = app(SupplierProductService::class);
        $this->link($links, $stationery, $notebook, ['supplier_code' => 'PA-CAD-96', 'is_preferred' => true, 'minimum_quantity' => '10']);
        $this->link($links, $dualRole, $notebook, ['supplier_code' => 'HZ-778', 'reference' => 'Linha escolar']);
        $this->link($links, $stationery, $pen, ['supplier_code' => 'PA-CAN-AZ', 'is_preferred' => true]);
        $this->link($links, $textile, $fabric, ['supplier_code' => 'TX-ALG-150', 'is_preferred' => true, 'lead_time_days' => 20]);

        $this->order('demo-purchase-draft', $stationery, [[$pen, '100', '0.80']], ['notes' => 'Aguardando aprovação do orçamento.'], confirm: false);
        $this->order('demo-purchase-confirmed', $textile, [[$fabric, '20', '17.50']], ['expected_date' => now()->addDays(10)->format('Y-m-d'), 'freight' => '40.00', 'payment_condition' => 'term', 'payment_method' => 'bank_slip', 'installments' => [['due_date' => now()->addDays(30)->format('Y-m-d'), 'amount' => '390.00']]]);
        $partial = $this->order('demo-purchase-partial', $stationery, [[$notebook, '30', '11.90'], [$pen, '200', '0.75'], [$binding, '1', '15.00']], [
            'order_date' => now()->subDays(20)->format('Y-m-d'),
            'expected_date' => now()->subDays(2)->format('Y-m-d'),
            'freight' => '25.00', 'discount' => '10.00', 'payment_condition' => 'cash', 'payment_method' => 'pix',
        ]);
        $this->receive($partial, 'demo-receipt-partial', [$notebook->id => '15', $pen->id => '200', $binding->id => '1'], now()->subDays(12)->format('Y-m-d'), 'Faltaram 15 cadernos; fornecedor enviará na próxima remessa.');
        $received = $this->order('demo-purchase-received', $dualRole, [[$whiteShirt, '10', '18.00'], [$blackShirt, '10', '18.00', '5.00']], [
            'order_date' => now()->subDays(40)->format('Y-m-d'),
            'expected_date' => now()->subDays(30)->format('Y-m-d'),
            'freight' => '30.00', 'insurance' => '5.00', 'payment_condition' => 'installments', 'payment_method' => 'bank_slip',
            'installments' => [
                ['due_date' => now()->subDays(10)->format('Y-m-d'), 'amount' => '130.00'],
                ['due_date' => now()->addDays(20)->format('Y-m-d'), 'amount' => '130.00'],
                ['due_date' => now()->addDays(50)->format('Y-m-d'), 'amount' => '130.00'],
            ],
        ]);
        $receipt = $this->receive($received, 'demo-receipt-received', [$whiteShirt->id => '10', $blackShirt->id => '10'], now()->subDays(31)->format('Y-m-d'));
        $this->returnItems($receipt, 'demo-return-shirts', $blackShirt, '2', 'Defeito de costura.');

        if (PurchaseOrder::query()->where('company_id', $company->id)->where('idempotency_key', 'demo-purchase-quick')->exists()) {
            return;
        }

        app(QuickPurchaseService::class)->register($company, $user, [
            'supplier_profile_id' => $artisan->id,
            'order_date' => now()->subDays(3)->format('Y-m-d'),
            'payment_condition' => 'cash',
            'payment_method' => 'cash',
            'document_reference' => 'Recibo 45',
            'items' => [['product_variant_id' => $wrapping->id, 'quantity' => '10', 'unit_cost' => '5.50']],
            'idempotency_key' => 'demo-purchase-quick',
        ]);
    }

    /**
     * @param  array<string, mixed>  $party
     * @param  array<string, mixed>  $profile
     */
    private function supplier(array $party, array $profile): SupplierProfile
    {
        $existing = SupplierProfile::query()->where('company_id', $this->company->id)->where('internal_code', $profile['internal_code'])->first();

        if ($existing !== null) {
            return $existing;
        }

        $isRegistered = isset($party['document']) && Party::query()->where('company_id', $this->company->id)->where('document', $party['document'])->exists();

        return app(SupplierService::class)->create($this->company, $this->user, [...$party, ...$profile, 'link_existing' => $isRegistered]);
    }

    private function variant(string $product, ?string $label = null): ?ProductVariant
    {
        return ProductVariant::query()->where('company_id', $this->company->id)->where('label', $label)
            ->whereHas('product', fn (Builder $query) => $query->where('name', $product))->first();
    }

    /** @param array<string, mixed> $data */
    private function link(SupplierProductService $links, SupplierProfile $supplier, ProductVariant $variant, array $data): void
    {
        $link = SupplierProduct::query()->firstOrNew(['supplier_profile_id' => $supplier->id, 'product_variant_id' => $variant->id]);
        $links->save($supplier, $link, [...$data, 'product_variant_id' => $variant->id]);
    }

    /**
     * @param  list<array{0: ProductVariant, 1: string, 2: string, 3?: string}>  $lines
     * @param  array<string, mixed>  $data
     */
    private function order(string $key, SupplierProfile $supplier, array $lines, array $data, bool $confirm = true): PurchaseOrder
    {
        // As datas são relativas ao dia do seed; um documento já criado nunca é regerado.
        $existing = PurchaseOrder::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->company, $this->user, [
            'supplier_profile_id' => $supplier->id,
            'order_date' => now()->subDays(5)->format('Y-m-d'),
            'payment_condition' => 'undefined',
            ...$data,
            'items' => array_map(fn (array $line): array => ['product_variant_id' => $line[0]->id, 'quantity' => $line[1], 'unit_cost' => $line[2], 'discount' => $line[3] ?? '0'], $lines),
            'idempotency_key' => $key,
        ], canManageCost: true);

        return $confirm && $order->status === PurchaseOrderStatus::Draft ? $orders->confirm($order, $this->user) : $order;
    }

    /** @param array<int, string> $quantities Quantidade por unidade comercializável. */
    private function receive(PurchaseOrder $order, string $key, array $quantities, string $date, ?string $notes = null): PurchaseReceipt
    {
        $existing = PurchaseReceipt::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        return app(PurchaseReceiptService::class)->receive($order, $this->user, [
            'received_at' => $date,
            'document_reference' => 'NF demonstração',
            'notes' => $notes,
            'idempotency_key' => $key,
            'items' => $order->items()->get()->map(fn ($item): array => ['purchase_order_item_id' => $item->id, 'quantity' => $quantities[$item->product_variant_id] ?? '0'])->all(),
        ]);
    }

    private function returnItems(PurchaseReceipt $receipt, string $key, ProductVariant $variant, string $quantity, string $reason): void
    {
        if (PurchaseReturn::query()->where('company_id', $this->company->id)->where('idempotency_key', $key)->exists()) {
            return;
        }

        app(PurchaseReturnService::class)->register($receipt, $this->user, [
            'returned_at' => now()->subDays(25)->format('Y-m-d'),
            'reason' => $reason,
            'idempotency_key' => $key,
            'items' => [['purchase_receipt_item_id' => $receipt->items()->where('product_variant_id', $variant->id)->sole()->id, 'quantity' => $quantity]],
        ]);
    }

    private function cnpj(string $base): string
    {
        return $base.BrazilianDocument::cnpjCheckDigits($base);
    }
}
