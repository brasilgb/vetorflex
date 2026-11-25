<?php

namespace App\Modules\Parties\Models;

use App\Modules\Parties\Enums\AddressType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyAddress extends Model
{
    protected $fillable = ['company_id', 'party_id', 'type', 'postal_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'country'];

    protected $attributes = ['country' => 'BR'];

    protected function casts(): array
    {
        return ['type' => AddressType::class];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
