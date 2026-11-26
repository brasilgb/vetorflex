<?php

namespace App\Modules\Parties\Concerns;

use App\Modules\Parties\Enums\AddressType;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Rules\ValidBrazilianDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Regras comuns aos cadastros que usam o domínio compartilhado de pessoas.
 */
trait ValidatesParty
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    protected function partyRules(?Party $party = null): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where('party_id', $party->id ?? 0);

        return [
            'type' => ['required', 'in:individual,company'],
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:20', new ValidBrazilianDocument($this->string('type')->value())],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'state_registration' => ['nullable', 'string', 'max:30'],
            'municipal_registration' => ['nullable', 'string', 'max:30'],
            'addresses' => ['sometimes', 'array', 'list', 'max:20'],
            'addresses.*.id' => ['nullable', 'integer', 'distinct', $owned('party_addresses')],
            'addresses.*.type' => ['required', Rule::enum(AddressType::class)],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:10'],
            'addresses.*.street' => ['nullable', 'string', 'max:255'],
            'addresses.*.number' => ['nullable', 'string', 'max:30'],
            'addresses.*.complement' => ['nullable', 'string', 'max:255'],
            'addresses.*.district' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'],
            'addresses.*.state' => ['nullable', 'string', 'size:2'],
            'contacts' => ['sometimes', 'array', 'list', 'max:20'],
            'contacts.*.id' => ['nullable', 'integer', 'distinct', $owned('party_contacts')],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            'contacts.*.whatsapp' => ['nullable', 'string', 'max:30'],
            'contacts.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
