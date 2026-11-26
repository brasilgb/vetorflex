<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Data\SaleAbilities;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Services\SaleDocumentBuilder;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base dos controladores de Vendas: concentra as regras de preço, desconto, custo e margem.
 */
abstract class SalesController extends Controller
{
    use InteractsWithCompany;

    protected function abilities(Request $request): SaleAbilities
    {
        return new SaleAbilities(
            canChangePrices: $this->allows($request, SalePermission::ChangePrices->value),
            canApplyDiscounts: $this->allows($request, SalePermission::ApplyDiscounts->value),
            canUseCustomItems: $this->allows($request, SalePermission::CustomItems->value),
        );
    }

    protected function canViewCost(Request $request): bool
    {
        return $this->allows($request, SalePermission::ViewCost->value);
    }

    /** Margem revela custo, por isso exige também consultar custos. */
    protected function canViewMargin(Request $request): bool
    {
        return $this->canViewCost($request) && $this->allows($request, SalePermission::ViewMargin->value);
    }

    protected function today(Request $request): string
    {
        return now($this->company($request)->timezone)->format('Y-m-d');
    }

    /** @return list<array{id: int, name: string}> */
    protected function sellers(Company $company): array
    {
        return array_values(app(SaleDocumentBuilder::class)->sellers($company)->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all());
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
