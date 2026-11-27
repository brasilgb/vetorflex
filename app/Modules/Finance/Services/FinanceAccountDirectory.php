<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FinanceAccount;
use App\Modules\Tenancy\Models\Company;

/**
 * Leitura pública das contas financeiras ativas, para outros módulos configurarem integrações (por exemplo, a conta de
 * cada forma de pagamento no PDV) sem acessar as tabelas do Financeiro.
 */
class FinanceAccountDirectory
{
    /** @return list<array{id: int, name: string}> */
    public function activeAccounts(Company $company): array
    {
        return array_values(FinanceAccount::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (FinanceAccount $account): array => ['id' => $account->id, 'name' => $account->name])->all());
    }

    /** @param list<int> $accountIds */
    public function belongToCompany(Company $company, array $accountIds): bool
    {
        $accountIds = array_values(array_unique($accountIds));

        return FinanceAccount::query()->where('company_id', $company->id)->whereIn('id', $accountIds)->count() === count($accountIds);
    }
}
