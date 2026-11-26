<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Tenancy\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $company_id
 * @property int $user_id
 * @property CompanyRole $role
 * @property bool $is_active
 * @property list<string>|null $permissions
 * @property-read User $user
 */
class CompanyMembership extends Pivot
{
    protected $table = 'company_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => CompanyRole::class, 'is_active' => 'boolean', 'permissions' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === CompanyRole::Owner;
    }
}
