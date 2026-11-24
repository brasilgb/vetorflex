<?php

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    /** @param array<string, mixed> $context */
    public function record(string $event, ?Company $company, ?User $user, ?Model $auditable = null, array $context = []): AuditLog
    {
        return AuditLog::create([
            'company_id' => $company?->id,
            'user_id' => $user?->id,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
