<?php

namespace App\Http\Controllers\Sales;

use App\Models\User;
use App\Modules\Core\Commerce\PaymentCondition;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Parties\Models\Party;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Services\SaleCustomers;
use App\Modules\Sales\Services\SaleDocumentBuilder;
use App\Modules\Sales\Services\SalesStock;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;

/**
 * Dados auxiliares dos formulários de orçamento e venda.
 */
class SaleFormOptions
{
    public function __construct(
        private TenantContext $tenants,
        private SaleCustomers $customers,
        private SaleDocumentBuilder $builder,
        private SalesStock $stock,
    ) {}

    /** @return array<string, mixed> */
    public function for(Request $request, ?Party $customer = null): array
    {
        $company = $request->attributes->get('company');
        $now = now($company->timezone);
        $allows = fn (SalePermission $permission): bool => $this->tenants->allows($request, $permission->value);

        return [
            'conditions' => PaymentCondition::options(),
            'methods' => PaymentMethod::options(),
            'deliveryMethods' => DeliveryMethod::options(),
            'sellers' => $this->builder->sellers($company)->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'abilities' => [
                'changePrices' => $allows(SalePermission::ChangePrices),
                'applyDiscounts' => $allows(SalePermission::ApplyDiscounts),
                'customItems' => $allows(SalePermission::CustomItems),
                'registerCustomers' => $allows(SalePermission::RegisterCustomers),
                'registerItems' => $allows(SalePermission::RegisterItems),
                'viewCost' => $allows(SalePermission::ViewCost),
                'quickSale' => $allows(SalePermission::Confirm) && $allows(SalePermission::Fulfill),
            ],
            'allowsAnonymous' => $this->builder->allowsAnonymous($company),
            'stockEnabled' => $this->stock->isEnabled($company),
            'today' => $now->format('Y-m-d'),
            'defaultValidUntil' => $now->addDays((int) config('sales.quote_validity_days'))->format('Y-m-d'),
            'currentUserId' => $request->user()->id,
            'selectedCustomer' => $customer === null ? null : $this->customers->summary($customer),
        ];
    }

    /** @return array{id: int, name: string}|null */
    public function selectedCustomer(Company $company, int $id): ?array
    {
        $party = $id > 0 ? Party::query()->customersOf($company)->find($id) : null;

        return $party === null ? null : ['id' => $party->id, 'name' => $party->name];
    }

    /** @return array{id: int, name: string}|null */
    public function selectedProduct(Company $company, int $id): ?array
    {
        $variant = $id > 0 ? ProductVariant::query()->where('company_id', $company->id)->with('product')->find($id) : null;

        return $variant === null ? null : ['id' => $variant->id, 'name' => $variant->displayName()];
    }
}
