<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agrupa os títulos renegociados e os novos títulos que os substituíram.
 *
 * @property int $id
 * @property string $kind
 * @property string $total
 * @property string $reason
 * @property string|null $user_name
 */
class FinanceRenegotiation extends Model
{
    protected $fillable = ['company_id', 'kind', 'total', 'reason', 'user_id', 'user_name'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }
}
