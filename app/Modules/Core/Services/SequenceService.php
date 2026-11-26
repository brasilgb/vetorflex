<?php

namespace App\Modules\Core\Services;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numeração sequencial por empresa. A linha fica bloqueada até o fim da transação do documento,
 * que por isso é obrigatória.
 */
class SequenceService
{
    public function next(Company $company, string $name): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A numeração exige uma transação aberta.');
        }

        DB::table('company_sequences')->upsert(
            [['company_id' => $company->id, 'name' => $name, 'value' => 1, 'created_at' => now(), 'updated_at' => now()]],
            ['company_id', 'name'],
            ['value' => DB::raw('company_sequences.value + 1'), 'updated_at' => now()],
        );

        return (int) DB::table('company_sequences')->where('company_id', $company->id)->where('name', $name)->lockForUpdate()->value('value');
    }
}
