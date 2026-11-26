<?php

namespace App\Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $company_id
 * @property int|null $user_id
 * @property string $event
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $occurred_at
 */
class AuditLog extends Model
{
    protected $fillable = ['company_id', 'user_id', 'event', 'auditable_type', 'auditable_id', 'context', 'occurred_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'datetime'];
    }
}
