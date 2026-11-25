<?php

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Importação em duas etapas. As linhas ficam criptografadas apenas até a confirmação.
 */
class CustomerImport extends Model
{
    use MassPrunable;

    public const PREVIEW = 'preview';

    public const COMPLETED = 'completed';

    protected $fillable = ['company_id', 'user_id', 'file_name', 'status', 'rows', 'report', 'completed_at'];

    protected $hidden = ['rows'];

    protected function casts(): array
    {
        return ['rows' => 'encrypted:array', 'report' => 'array', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('status', self::PREVIEW)->where('created_at', '<', now()->subDay());
    }
}
