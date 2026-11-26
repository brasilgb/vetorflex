<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Purchases\Enums\PurchaseOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property PurchaseOrderStatus|null $from_status
 * @property PurchaseOrderStatus $to_status
 * @property string|null $reason
 * @property string|null $user_name
 * @property CarbonImmutable $occurred_at
 */
class PurchaseStatusChange extends Model
{
    protected $table = 'purchase_status_history';

    public $timestamps = false;

    protected $fillable = ['company_id', 'purchase_order_id', 'from_status', 'to_status', 'reason', 'user_id', 'user_name', 'occurred_at'];

    protected function casts(): array
    {
        return ['from_status' => PurchaseOrderStatus::class, 'to_status' => PurchaseOrderStatus::class, 'occurred_at' => 'datetime'];
    }
}
