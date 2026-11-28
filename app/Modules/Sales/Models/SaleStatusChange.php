<?php

namespace App\Modules\Sales\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Histórico de situação de orçamentos e pedidos. As situações são gravadas como texto, pois cada documento tem a sua.
 *
 * @property int $id
 * @property int|null $sale_quote_id
 * @property int|null $sale_order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string|null $user_name
 * @property CarbonImmutable $occurred_at
 */
class SaleStatusChange extends Model
{
    protected $table = 'sale_status_history';

    public $timestamps = false;

    protected $fillable = ['company_id', 'sale_quote_id', 'sale_order_id', 'from_status', 'to_status', 'reason', 'user_id', 'user_name', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
