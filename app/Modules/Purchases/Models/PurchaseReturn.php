<?php

namespace App\Modules\Purchases\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devolução comercial. O estorno técnico pertence ao recebimento.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $purchase_order_id
 * @property int $purchase_receipt_id
 * @property CarbonImmutable $returned_at
 * @property string $reason
 * @property string $total_value
 * @property string|null $user_name
 * @property string $request_hash
 * @property-read PurchaseOrder $order
 * @property-read PurchaseReceipt $receipt
 */
class PurchaseReturn extends Model
{
    protected $fillable = ['company_id', 'number', 'purchase_order_id', 'purchase_receipt_id', 'returned_at', 'reason', 'total_value', 'user_id', 'user_name', 'idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'returned_at' => 'date:Y-m-d', 'total_value' => 'decimal:2'];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<PurchaseReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    /** @return HasMany<PurchaseReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class)->orderBy('id');
    }
}
