<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Parties\Services\PartyService;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fornecedor é o papel `supplier` de uma pessoa compartilhada. Situação e dados comerciais ficam no perfil.
 */
class SupplierService
{
    public const PROFILE_ATTRIBUTES = ['internal_code', 'lead_time_days', 'payment_terms', 'minimum_order', 'notes'];

    public function __construct(private PartyService $parties, private AuditService $audit) {}

    /**
     * Com `link_existing`, uma pessoa já cadastrada com o mesmo documento recebe o papel fornecedor sem ter seus dados alterados.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, User $user, array $data): SupplierProfile
    {
        return DB::transaction(function () use ($company, $user, $data): SupplierProfile {
            $party = $this->parties->findByDocument($company, $data['document'] ?? null);

            if ($party !== null && ! ($data['link_existing'] ?? false)) {
                throw ValidationException::withMessages(['link_existing' => "Já existe uma pessoa com este documento: {$party->name}. Confirme para torná-la fornecedora."]);
            }

            if ($party?->supplierProfile !== null) {
                throw ValidationException::withMessages(['document' => 'Esta pessoa já é fornecedora.']);
            }

            $party === null
                ? $party = $this->parties->save($company, new Party, PartyRole::SUPPLIER, Arr::except($data, 'notes'))
                : $this->parties->addRole($party, PartyRole::SUPPLIER);

            $supplier = SupplierProfile::create([
                ...Arr::only($data, self::PROFILE_ATTRIBUTES),
                'company_id' => $company->id,
                'party_id' => $party->id,
                'registered_by' => $user->id,
            ]);
            $this->audit->record('supplier.created', $company, $user, $supplier, ['linked' => $party->wasRecentlyCreated === false]);

            return $supplier;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SupplierProfile $supplier, User $user, array $data): SupplierProfile
    {
        return DB::transaction(function () use ($supplier, $user, $data): SupplierProfile {
            $this->parties->save($supplier->party->company, $supplier->party, PartyRole::SUPPLIER, Arr::except($data, 'notes'));
            $supplier->update(Arr::only($data, self::PROFILE_ATTRIBUTES));
            $this->audit->record('supplier.updated', $supplier->party->company, $user, $supplier);

            return $supplier;
        });
    }

    public function setActive(SupplierProfile $supplier, User $user, bool $isActive): void
    {
        $supplier->update(['is_active' => $isActive]);
        $this->audit->record($isActive ? 'supplier.reactivated' : 'supplier.deactivated', $supplier->party->company, $user, $supplier);
    }
}
