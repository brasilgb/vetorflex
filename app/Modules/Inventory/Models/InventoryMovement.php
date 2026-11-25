<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Products\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Registro imutável. Correções são feitas por movimentações compensatórias.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_variant_id
 * @property MovementType $type
 * @property string $quantity Quantidade com sinal: positiva para entrada, negativa para saída.
 * @property string $balance_before
 * @property string $balance_after
 * @property string|null $unit_cost
 * @property MovementOrigin $origin
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property int|null $reversal_of_id
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string|null $reason
 * @property string|null $idempotency_key
 * @property string $request_hash
 * @property CarbonImmutable $occurred_at
 * @property-read ProductVariant $variant
 * @property-read InventoryMovement|null $reversal
 */
class InventoryMovement extends Model
{
    protected $fillable = ['company_id', 'product_variant_id', 'type', 'quantity', 'balance_before', 'balance_after', 'unit_cost', 'origin', 'reference_type', 'reference_id', 'reversal_of_id', 'user_id', 'user_name', 'reason', 'idempotency_key', 'request_hash', 'occurred_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Movimentações de estoque são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Movimentações de estoque não podem ser excluídas.'));
    }

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'origin' => MovementOrigin::class,
            'quantity' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return HasOne<InventoryMovement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
