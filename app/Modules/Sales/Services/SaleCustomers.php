<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyAddress;
use App\Modules\Parties\Models\PartyRole;
use App\Modules\Parties\Services\PartyService;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clientes dentro de Vendas: o papel `customer` da pessoa compartilhada. Funciona sem o módulo Clientes e nunca altera
 * dados de uma pessoa existente; uma pessoa já cadastrada (por exemplo, fornecedora) apenas recebe o papel.
 */
class SaleCustomers
{
    private const LIMIT = 10;

    public function __construct(private PartyService $parties, private AuditService $audit) {}

    /** @return Collection<int, Party> */
    public function search(Company $company, string $term): Collection
    {
        $term = trim($term);
        $digits = preg_replace('/\D/', '', $term) ?? '';

        return Party::query()->customersOf($company)->where('parties.is_active', true)
            ->where(function (Builder $query) use ($term, $digits): void {
                $query->where('parties.name', 'like', "%{$term}%")->orWhere('parties.trade_name', 'like', "%{$term}%");

                foreach (array_unique(array_filter([$term, strlen($digits) >= 3 ? $digits : null])) as $value) {
                    $query->orWhere('parties.phone', 'like', "%{$value}%")
                        ->orWhere('parties.mobile', 'like', "%{$value}%")
                        ->orWhere('parties.whatsapp', 'like', "%{$value}%");
                }

                if (strlen($digits) >= 3) {
                    $query->orWhere('parties.document', 'like', "%{$digits}%");
                }
            })
            ->with('addresses')->orderBy('parties.name')->orderBy('parties.id')->limit(self::LIMIT)->get();
    }

    /**
     * Cadastro simplificado. Com `link_existing`, a pessoa com o mesmo documento recebe o papel cliente sem alteração.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSimplified(Company $company, User $user, array $data): Party
    {
        return DB::transaction(function () use ($company, $user, $data): Party {
            $existing = $this->parties->findByDocument($company, $data['document'] ?? null);

            if ($existing !== null) {
                if ($existing->hasRole(PartyRole::CUSTOMER)) {
                    return $existing;
                }

                if (! ($data['link_existing'] ?? false)) {
                    throw ValidationException::withMessages(['link_existing' => "Já existe uma pessoa com este documento: {$existing->name}. Confirme para torná-la cliente."]);
                }

                $this->parties->addRole($existing, PartyRole::CUSTOMER);
                $this->audit->record('sales.customer_linked', $company, $user, $existing);

                return $existing;
            }

            $attributes = Arr::only($data, ['type', 'name', 'document', 'email', 'phone']);

            if (filled($data['address']['street'] ?? null)) {
                $attributes['addresses'] = [['type' => 'delivery', ...Arr::only($data['address'], ['postal_code', 'street', 'number', 'complement', 'district', 'city', 'state'])]];
            }

            $party = $this->parties->save($company, new Party, PartyRole::CUSTOMER, $attributes);
            $this->audit->record('sales.customer_created', $company, $user, $party);

            return $party;
        });
    }

    /** O cliente precisa ter o papel `customer` nesta empresa. Inativos só são aceitos se já estavam no documento. */
    public function resolve(Company $company, int $partyId, ?int $currentPartyId): Party
    {
        $party = Party::query()->customersOf($company)->with('addresses')->find($partyId);

        if ($party === null) {
            throw ValidationException::withMessages(['customer_party_id' => 'Selecione um cliente desta empresa.']);
        }

        if (! $party->is_active && $party->id !== $currentPartyId) {
            throw ValidationException::withMessages(['customer_party_id' => 'O cliente está inativo.']);
        }

        return $party;
    }

    /** @return array<string, string|null> */
    public function snapshot(Party $party, ?string $contact): array
    {
        $address = $party->addresses->sortBy('id')->first();

        return [
            'name' => $party->name,
            'trade_name' => $party->trade_name,
            'document' => BrazilianDocument::format($party->document),
            'state_registration' => $party->state_registration,
            'email' => $party->email,
            'phone' => $party->phone ?? $party->mobile ?? $party->whatsapp,
            'contact' => $contact,
            'address' => $address === null ? null : self::addressText($address),
        ];
    }

    public static function addressText(PartyAddress $address): string
    {
        return collect([
            trim("{$address->street}, {$address->number}", ', '),
            $address->complement,
            $address->district,
            trim("{$address->city}/{$address->state}", '/'),
            $address->postal_code,
        ])->filter()->implode(' · ');
    }

    /** @return array<string, mixed> */
    public function summary(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'trade_name' => $party->trade_name,
            'document' => BrazilianDocument::format($party->document),
            'phone' => $party->phone ?? $party->mobile ?? $party->whatsapp,
            'email' => $party->email,
            'is_active' => $party->is_active,
            'addresses' => $party->addresses->sortBy('id')->map(fn (PartyAddress $address): array => ['id' => $address->id, 'text' => self::addressText($address)])->values()->all(),
        ];
    }
}
