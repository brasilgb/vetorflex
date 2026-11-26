<?php

namespace App\Modules\Parties\Services;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Gravação da pessoa compartilhada entre papéis comerciais. Cada módulo grava apenas o próprio perfil.
 */
class PartyService
{
    private const ATTRIBUTES = ['type', 'name', 'trade_name', 'document', 'email', 'phone', 'mobile', 'whatsapp', 'state_registration', 'municipal_registration', 'birth_date', 'notes'];

    /**
     * Somente os dados presentes são alterados. Endereços e contatos, quando enviados, passam a ser a lista completa:
     * os identificados são atualizados, os novos são criados e os ausentes são removidos.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Company $company, Party $party, string $role, array $data): Party
    {
        return DB::transaction(function () use ($company, $party, $role, $data): Party {
            $attributes = Arr::only($data, self::ATTRIBUTES);

            if (array_key_exists('document', $attributes)) {
                $attributes['document'] = BrazilianDocument::normalize($attributes['document']);
            }

            $party->fill([...$attributes, 'company_id' => $company->id])->save();
            $this->addRole($party, $role);

            foreach (['addresses', 'contacts'] as $relation) {
                if (array_key_exists($relation, $data)) {
                    $this->syncRelation($party, $relation, array_values($data[$relation] ?? []));
                }
            }

            return $party;
        });
    }

    public function addRole(Party $party, string $role): void
    {
        $party->roles()->firstOrCreate(['role' => $role], ['company_id' => $party->company_id]);
    }

    public function findByDocument(Company $company, ?string $document): ?Party
    {
        $document = BrazilianDocument::normalize($document);

        return $document === null ? null : Party::query()->where('company_id', $company->id)->where('document', $document)->first();
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncRelation(Party $party, string $relation, array $rows): void
    {
        $query = $relation === 'addresses' ? $party->addresses() : $party->contacts();
        $query->whereNotIn('id', array_filter(array_column($rows, 'id')))->delete();

        foreach ($rows as $row) {
            $attributes = [...Arr::except($row, 'id'), 'company_id' => $party->company_id];
            $query = $relation === 'addresses' ? $party->addresses() : $party->contacts();
            $existing = isset($row['id']) ? $query->whereKey($row['id'])->first() : null;

            if ($existing === null) {
                ($relation === 'addresses' ? $party->addresses() : $party->contacts())->create($attributes);

                continue;
            }

            $existing->update($attributes);
        }
    }
}
