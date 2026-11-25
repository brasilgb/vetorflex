<?php

namespace App\Modules\ModuleManager\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyModule extends Model
{
    protected $fillable = ['company_id', 'platform_module_id', 'activated_by', 'activated_at', 'deactivated_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'deactivated_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(PlatformModule::class, 'platform_module_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
