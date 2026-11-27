<?php

use App\Models\User;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\ModuleManager\Models\CompanyModule;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Purchases\Enums\PurchasePermission;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Autentica um membro da empresa com o módulo Produtos habilitado. Sem permissões informadas, é proprietário.
 *
 * @param  list<ProductPermission>|null  $permissions
 */
function actingAsProductsMember(Company $company, ?array $permissions = null): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, [
        'role' => $permissions === null ? 'owner' : 'operator',
        'is_active' => true,
        'permissions' => array_column($permissions ?? [], 'value'),
    ]);
    $module = PlatformModule::query()->firstOrCreate(['identifier' => 'products'], ['name' => 'Produtos', 'description' => 'Catálogo.', 'permissions' => ProductPermission::catalog()]);
    CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);
    test()->actingAs($user)->withSession(['active_company_id' => $company->id]);

    return $user;
}

/**
 * Autentica um membro com Produtos e Compras habilitados. Sem permissões informadas, é proprietário.
 *
 * @param  list<PurchasePermission|ProductPermission>|null  $permissions
 */
function actingAsPurchasesMember(Company $company, ?array $permissions = null): User
{
    $user = actingAsProductsMember($company, $permissions);
    $module = PlatformModule::query()->firstOrCreate(['identifier' => 'purchases'], ['name' => 'Compras', 'description' => 'Compras.', 'dependencies' => ['products'], 'permissions' => PurchasePermission::catalog()]);
    CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);

    return $user;
}

/** Unidade comercializável de um produto com estoque controlado, ou de um serviço. */
function purchasableVariant(Company $company, ?string $costPrice = null, bool $isFractional = false, bool $isService = false): ProductVariant
{
    $unit = UnitOfMeasure::factory()->create(['company_id' => $company->id, 'allows_fraction' => $isFractional]);
    $product = Product::factory()->state($isService ? ['type' => ProductType::Service, 'tracks_inventory' => false] : [])
        ->create(['company_id' => $company->id, 'unit_of_measure_id' => $unit->id]);

    return ProductVariant::factory()->for($product)->create(['cost_price' => $costPrice]);
}

/**
 * Dados de um pedido de compra à vista, no formato da requisição.
 *
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function purchaseOrderData(SupplierProfile $supplier, array $items, array $overrides = []): array
{
    return [
        'supplier_profile_id' => $supplier->id,
        'order_date' => now()->format('Y-m-d'),
        'expected_date' => null,
        'discount' => '0',
        'freight' => '0',
        'insurance' => '0',
        'other_costs' => '0',
        'payment_condition' => 'undefined',
        'payment_method' => null,
        'installments' => [],
        'items' => $items,
        ...$overrides,
    ];
}

/**
 * Autentica um membro com Vendas habilitado. Produtos (e, com ele, o estoque) só é habilitado quando pedido.
 * Sem permissões informadas, é proprietário.
 *
 * @param  list<SalePermission|ProductPermission>|null  $permissions
 */
function actingAsSalesMember(Company $company, ?array $permissions = null, bool $withStock = false): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, [
        'role' => $permissions === null ? 'owner' : 'operator',
        'is_active' => true,
        'permissions' => array_column($permissions ?? [], 'value'),
    ]);
    $modules = [['sales', 'Vendas', SalePermission::catalog()], ...($withStock ? [['products', 'Produtos', ProductPermission::catalog()]] : [])];

    foreach ($modules as [$identifier, $name, $catalog]) {
        $module = PlatformModule::query()->firstOrCreate(['identifier' => $identifier], ['name' => $name, 'description' => $name, 'permissions' => $catalog]);
        CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);
    }

    test()->actingAs($user)->withSession(['active_company_id' => $company->id]);

    return $user;
}

/** Unidade comercializável com preço de venda e, quando informado, estoque inicial e custo médio. */
function sellableVariant(Company $company, string $price = '10.00', ?string $stock = null, ?string $averageCost = null, bool $isService = false, bool $isFractional = false): ProductVariant
{
    $variant = purchasableVariant($company, isFractional: $isFractional, isService: $isService);
    $variant->update(['sale_price' => $price, 'promotional_price' => null]);

    if ($stock !== null) {
        app(InventoryService::class)->recordEntry(new StockOperation(companyId: $company->id, variantId: $variant->id, quantity: $stock, entryValue: $averageCost === null ? null : (string) BigDecimal::of($averageCost)->multipliedBy($stock)->toScale(2)));
    }

    return $variant->refresh();
}

/**
 * Dados de uma venda à vista, no formato da requisição.
 *
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function saleOrderData(?Party $customer, array $items, array $overrides = []): array
{
    return [
        'customer_party_id' => $customer?->id,
        'order_date' => now()->format('Y-m-d'),
        'delivery_method' => 'pickup',
        'discount' => '0',
        'freight' => '0',
        'other_costs' => '0',
        'payment_condition' => 'undefined',
        'installments' => [],
        'items' => $items,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/** Pessoa com o papel cliente. */
function saleCustomer(Company $company, array $attributes = []): Party
{
    $party = Party::factory()->create(['company_id' => $company->id, ...$attributes]);
    $party->roles()->create(['company_id' => $company->id, 'role' => PartyRole::CUSTOMER]);

    return $party;
}

/**
 * Autentica um membro com o Financeiro habilitado e, quando pedidos, outros módulos (identificadores).
 * Sem permissões informadas, é proprietário.
 *
 * @param  list<FinancePermission|SalePermission|PurchasePermission|ProductPermission>|null  $permissions
 * @param  list<string>  $alsoEnable
 */
function actingAsFinanceMember(Company $company, ?array $permissions = null, array $alsoEnable = []): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, [
        'role' => $permissions === null ? 'owner' : 'operator',
        'is_active' => true,
        'permissions' => array_column($permissions ?? [], 'value'),
    ]);
    $catalogs = [
        'finance' => ['Financeiro', FinancePermission::catalog(), []],
        'sales' => ['Vendas', SalePermission::catalog(), []],
        'products' => ['Produtos', ProductPermission::catalog(), []],
        'purchases' => ['Compras', PurchasePermission::catalog(), ['products']],
    ];

    foreach (['finance', ...$alsoEnable] as $identifier) {
        [$name, $catalog, $dependencies] = $catalogs[$identifier];
        $module = PlatformModule::query()->firstOrCreate(['identifier' => $identifier], ['name' => $name, 'description' => $name, 'dependencies' => $dependencies, 'permissions' => $catalog]);
        CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);
    }

    test()->actingAs($user)->withSession(['active_company_id' => $company->id]);

    return $user;
}

/** Conta financeira ativa com saldo inicial. */
function financeAccount(Company $company, string $openingBalance = '0.00', string $name = 'Caixa'): FinanceAccount
{
    return FinanceAccount::query()->create([
        'company_id' => $company->id,
        'name' => $name,
        'type' => 'cash',
        'opening_balance' => $openingBalance,
        'opening_date' => now($company->timezone)->subMonth()->format('Y-m-d'),
        'is_active' => true,
    ]);
}

/**
 * Dados de um título manual, no formato da requisição.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function financeTitleData(string $kind = 'receivable', array $overrides = []): array
{
    return [
        'kind' => $kind,
        'description' => $kind === 'receivable' ? 'Serviço prestado' : 'Aluguel',
        'amount' => '100.00',
        'competence_date' => now()->format('Y-m-d'),
        'due_date' => now()->addDays(5)->format('Y-m-d'),
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/**
 * Dados de uma baixa, no formato da requisição.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function settlementData(FinanceAccount $account, string $principal, array $overrides = []): array
{
    return [
        'finance_account_id' => $account->id,
        'settled_at' => now()->format('Y-m-d'),
        'method' => 'pix',
        'principal' => $principal,
        'interest' => '0',
        'fine' => '0',
        'discount' => '0',
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/**
 * Autentica um membro com o PDV habilitado e, quando pedidos, outros módulos (identificadores).
 * Sem permissões informadas, é proprietário.
 *
 * @param  list<PosPermission|SalePermission|FinancePermission>|null  $permissions
 * @param  list<string>  $alsoEnable
 */
function actingAsPosMember(Company $company, ?array $permissions = null, array $alsoEnable = [], ?User $user = null): User
{
    $user ??= User::factory()->create();
    $user->companies()->syncWithoutDetaching([$company->id => [
        'role' => $permissions === null ? 'owner' : 'operator',
        'is_active' => true,
        'permissions' => array_column($permissions ?? [], 'value'),
    ]]);
    $catalogs = [
        'pos' => ['PDV', PosPermission::catalog(), []],
        'finance' => ['Financeiro', FinancePermission::catalog(), []],
        'sales' => ['Vendas', SalePermission::catalog(), []],
        'products' => ['Produtos', ProductPermission::catalog(), []],
    ];

    foreach (['pos', ...$alsoEnable] as $identifier) {
        [$name, $catalog, $dependencies] = $catalogs[$identifier];
        $module = PlatformModule::query()->firstOrCreate(['identifier' => $identifier], ['name' => $name, 'description' => $name, 'dependencies' => $dependencies, 'permissions' => $catalog]);
        CompanyModule::query()->firstOrCreate(['company_id' => $company->id, 'platform_module_id' => $module->id], ['activated_by' => $user->id, 'activated_at' => now()]);
    }

    test()->actingAs($user)->withSession(['active_company_id' => $company->id]);

    return $user;
}

/** Terminal ativo e sessão aberta pelo usuário autenticado, com troco inicial. */
function openPosSession(Company $company, string $openingAmount = '100.00', string $code = 'CX01'): PosSession
{
    $terminal = PosTerminal::query()->firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => "Caixa {$code}", 'is_active' => true]);
    test()->post("/pos/terminals/{$terminal->id}/open", ['opening_amount' => $openingAmount, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

    return PosSession::query()->where('pos_terminal_id', $terminal->id)->where('status', 'open')->sole();
}

/**
 * Dados de finalização no caixa.
 *
 * @param  list<array<string, mixed>>  $items
 * @param  list<array<string, mixed>>  $payments
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function posCart(array $items, array $payments, array $overrides = []): array
{
    return ['items' => $items, 'payments' => $payments, 'idempotency_key' => (string) Str::uuid(), ...$overrides];
}
