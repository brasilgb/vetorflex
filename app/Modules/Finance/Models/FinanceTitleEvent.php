<?php

namespace App\Modules\Finance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Histórico do título: criação, baixas, estornos, ajustes, cancelamento e renegociação.
 *
 * @property int $id
 * @property string $event
 * @property string $description
 * @property string|null $amount
 * @property string|null $user_name
 * @property CarbonImmutable $occurred_at
 */
class FinanceTitleEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['company_id', 'finance_title_id', 'event', 'description', 'amount', 'user_id', 'user_name', 'occurred_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
