<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Parties\Models\Party;
use Database\Factories\Modules\Purchases\Models\SupplierProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de fornecedor de uma pessoa. A situação é própria: inativar o fornecedor não inativa o cliente.
 *
 * @property int $id
 * @property int $company_id
 * @property int $party_id
 * @property string|null $internal_code
 * @property int|null $lead_time_days
 * @property string|null $payment_terms
 * @property string|null $minimum_order
 * @property string|null $notes
 * @property bool $is_active
 * @property-read Party $party
 */
class SupplierProfile extends Model
{
    /** @use HasFactory<SupplierProfileFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'party_id', 'internal_code', 'lead_time_days', 'payment_terms', 'minimum_order', 'notes', 'is_active', 'registered_by'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['minimum_order' => 'decimal:2', 'is_active' => 'boolean', 'lead_time_days' => 'integer'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasMany<SupplierProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    protected static function newFactory(): SupplierProfileFactory
    {
        return SupplierProfileFactory::new();
    }
}
