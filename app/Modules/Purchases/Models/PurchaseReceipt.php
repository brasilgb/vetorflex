<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Purchases\Enums\ReceiptStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $purchase_order_id
 * @property ReceiptStatus $status
 * @property CarbonImmutable $received_at
 * @property string|null $document_reference
 * @property string|null $notes
 * @property string $total_cost
 * @property string|null $user_name
 * @property string $request_hash
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversed_by_name
 * @property string|null $reversal_reason
 * @property-read PurchaseOrder $order
 */
class PurchaseReceipt extends Model
{
    protected $fillable = ['company_id', 'number', 'purchase_order_id', 'status', 'received_at', 'document_reference', 'notes', 'total_cost', 'user_id', 'user_name', 'idempotency_key', 'request_hash', 'reversed_at', 'reversed_by_name', 'reversal_reason'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'status' => ReceiptStatus::class, 'received_at' => 'date:Y-m-d', 'total_cost' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return HasMany<PurchaseReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class)->orderBy('id');
    }

    /** @return HasMany<PurchaseReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }
}
