<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Services\PartyService;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Pessoas vinculadas a títulos, pelo domínio compartilhado. A busca alcança qualquer pessoa da empresa
 * (cliente, fornecedor ou contato financeiro) e o cadastro simplificado usa o papel `finance_contact`,
 * sem exigir Clientes ou Compras e sem alterar pessoas existentes.
 */
class FinanceParties
{
    public const ROLE = 'finance_contact';

    public function __construct(private PartyService $parties, private AuditService $audit) {}

    /** @return Collection<int, Party> */
    public function search(Company $company, string $term): Collection
    {
        $digits = preg_replace('/\D/', '', $term) ?? '';

        return Party::query()->where('company_id', $company->id)->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('name', 'like', "%{$term}%")->orWhere('trade_name', 'like', "%{$term}%")
                ->when(strlen($digits) >= 3, fn (Builder $query) => $query->orWhere('document', 'like', "%{$digits}%")))
            ->orderBy('name')->orderBy('id')->limit(10)->get();
    }

    /** @param array<string, mixed> $data */
    public function createSimplified(Company $company, User $user, array $data): Party
    {
        $existing = $this->parties->findByDocument($company, $data['document'] ?? null);

        if ($existing !== null) {
            throw ValidationException::withMessages(['document' => "Já existe uma pessoa com este documento: {$existing->name}. Pesquise e selecione-a."]);
        }

        $party = $this->parties->save($company, new Party, self::ROLE, Arr::only($data, ['type', 'name', 'document', 'email', 'phone']));
        $this->audit->record('finance.party_created', $company, $user, $party);

        return $party;
    }

    /** @return array{id: int, name: string, document: string|null} */
    public function summary(Party $party): array
    {
        return ['id' => $party->id, 'name' => $party->name, 'document' => BrazilianDocument::format($party->document)];
    }
}
