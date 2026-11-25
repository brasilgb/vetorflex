<?php

namespace App\Modules\ModuleManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformModule extends Model
{
    public const DEVELOPMENT = 'development';

    public const IMPLEMENTED = 'implemented';

    protected $fillable = ['identifier', 'name', 'description', 'icon', 'category', 'version', 'development_status', 'commercially_available', 'dependencies', 'optional_integrations', 'permissions', 'settings', 'sort_order'];

    protected function casts(): array
    {
        return [
            'commercially_available' => 'boolean',
            'dependencies' => 'array',
            'optional_integrations' => 'array',
            'permissions' => 'array',
            'settings' => 'array',
        ];
    }

    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }
}
