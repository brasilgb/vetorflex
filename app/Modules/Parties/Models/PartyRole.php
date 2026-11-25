<?php

namespace App\Modules\Parties\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Papel comercial de uma pessoa. Novos papéis (fornecedor, transportadora) reutilizam a mesma Party.
 */
class PartyRole extends Model
{
    public const CUSTOMER = 'customer';

    protected $fillable = ['company_id', 'party_id', 'role'];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
