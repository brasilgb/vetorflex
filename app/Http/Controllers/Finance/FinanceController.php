<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Modules\Core\Commerce\PaymentMethod;
use App\Modules\Finance\Enums\FinancePermission;
use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Services\FinanceCategoryService;
use App\Modules\Finance\Services\FinanceModule;
use App\Modules\Finance\Support\FinancePresenter;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base dos controladores do Financeiro: empresa ativa, data local, permissões e opções dos formulários.
 */
abstract class FinanceController extends Controller
{
    use InteractsWithCompany;

    protected function today(Request $request): string
    {
        return now($this->company($request)->timezone)->format('Y-m-d');
    }

    protected function can(Request $request, FinancePermission $permission): bool
    {
        return $this->allows($request, $permission->value);
    }

    /** Para permissões que dependem do registro (a receber ou a pagar); as demais ficam nas rotas. */
    protected function authorizeFinance(Request $request, FinancePermission $permission): void
    {
        abort_unless(app(FinanceModule::class)->isEnabled($this->company($request)) && $this->can($request, $permission), 403);
    }

    /** @return list<array{id: int, name: string}> */
    protected function activeAccounts(Company $company): array
    {
        return array_values(FinanceAccount::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')
            ->get(['id', 'name'])->map(fn (FinanceAccount $account): array => ['id' => $account->id, 'name' => $account->name])->all());
    }

    /** @return list<array{id: int, kind: string, name: string, full_name: string, parent_id: int|null, is_active: bool}> */
    protected function categories(Company $company, bool $onlyActive = true): array
    {
        return array_values(app(FinanceCategoryService::class)->forCompany($company)
            ->when($onlyActive, fn ($categories) => $categories->where('is_active', true))
            ->map(fn (FinanceCategory $category): array => app(FinancePresenter::class)->category($category))->all());
    }

    /** @return list<array{value: string, label: string}> */
    protected function paymentMethods(): array
    {
        return PaymentMethod::options();
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
