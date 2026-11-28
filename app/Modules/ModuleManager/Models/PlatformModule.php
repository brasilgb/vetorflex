<?php

namespace App\Modules\ModuleManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $identifier
 * @property string $name
 * @property string|null $version
 * @property string $development_status
 * @property bool $commercially_available
 * @property list<string>|null $dependencies
 * @property list<array{key: string, label: string}>|null $permissions
 * @property string $description
 * @property string|null $icon
 * @property string $category
 * @property string|null $tagline
 * @property list<string>|null $benefits
 * @property string|null $monthly_price Valor mensal configurado pelo RootAdmin; nulo quando não configurado.
 * @property int $sort_order
 */
class PlatformModule extends Model
{
    public const DEVELOPMENT = 'development';

    public const IMPLEMENTED = 'implemented';

    protected $fillable = ['identifier', 'name', 'description', 'icon', 'category', 'version', 'development_status', 'commercially_available', 'dependencies', 'optional_integrations', 'permissions', 'settings', 'sort_order', 'tagline', 'benefits', 'monthly_price'];

    protected function casts(): array
    {
        return [
            'commercially_available' => 'boolean',
            'dependencies' => 'array',
            'optional_integrations' => 'array',
            'permissions' => 'array',
            'settings' => 'array',
            'benefits' => 'array',
            'monthly_price' => 'decimal:2',
        ];
    }

    /** @return HasMany<CompanyModule, $this> */
    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }
}
