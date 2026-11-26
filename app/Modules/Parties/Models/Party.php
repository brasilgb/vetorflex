<?php

namespace App\Modules\Parties\Models;

use App\Modules\Customers\Models\CustomerProfile;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Parties\Models\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pessoa física ou jurídica de uma empresa, compartilhada entre papéis comerciais.
 *
 * @property int $id
 * @property int $company_id
 * @property string $type
 * @property string $name
 * @property string|null $trade_name
 * @property string|null $document
 * @property string|null $state_registration
 * @property string|null $municipal_registration
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $whatsapp
 * @property CarbonImmutable|null $birth_date
 * @property string|null $notes
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read CustomerProfile|null $customerProfile
 * @property-read SupplierProfile|null $supplierProfile
 */
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'type', 'name', 'trade_name', 'document', 'state_registration', 'municipal_registration', 'email', 'phone', 'mobile', 'whatsapp', 'birth_date', 'notes', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'birth_date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<PartyRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(PartyRole::class);
    }

    /** @return HasMany<PartyAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(PartyAddress::class);
    }

    /** @return HasMany<PartyContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(PartyContact::class);
    }

    /** @return HasOne<CustomerProfile, $this> */
    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    /** @return HasOne<SupplierProfile, $this> */
    public function supplierProfile(): HasOne
    {
        return $this->hasOne(SupplierProfile::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->exists();
    }

    /** @param Builder<Party> $query */
    #[Scope]
    protected function customersOf(Builder $query, Company $company): void
    {
        $query->where('parties.company_id', $company->id)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('company_id', $company->id)->where('role', PartyRole::CUSTOMER));
    }

    protected static function newFactory(): PartyFactory
    {
        return PartyFactory::new();
    }
}
