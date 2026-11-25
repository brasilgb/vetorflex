<?php

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    protected $fillable = ['company_id', 'party_id', 'registered_by', 'internal_code', 'credit_limit', 'payment_terms', 'classification', 'commercial_notes'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
