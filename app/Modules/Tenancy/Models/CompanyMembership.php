<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $company_id
 * @property int $user_id
 * @property CompanyRole $role
 * @property bool $is_active
 * @property list<string>|null $permissions
 */
class CompanyMembership extends Pivot
{
    protected $table = 'company_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => CompanyRole::class, 'is_active' => 'boolean', 'permissions' => 'array'];
    }

    public function isOwner(): bool
    {
        return $this->role === CompanyRole::Owner;
    }
}
