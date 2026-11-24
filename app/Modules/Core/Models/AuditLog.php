<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['company_id', 'user_id', 'event', 'auditable_type', 'auditable_id', 'context', 'occurred_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'occurred_at' => 'datetime'];
    }
}
