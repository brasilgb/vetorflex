<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\ModuleManager\Models\CompanyModule;
use Database\Factories\Modules\Tenancy\Models\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $timezone
 * @property string $currency
 * @property array<string, mixed>|null $settings
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'status', 'timezone', 'currency', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(CompanyMembership::class)->withPivot(['role', 'is_active', 'permissions'])->withTimestamps();
    }

    public function moduleAssignments(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
