<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Products\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Histórico imutável do custo médio.
 *
 * @property int $id
 * @property int $company_id
 * @property int $product_variant_id
 * @property int $inventory_movement_id
 * @property string $previous_quantity
 * @property string|null $previous_cost
 * @property string $previous_cost_source
 * @property string|null $entry_value
 * @property string $new_quantity
 * @property string|null $new_cost
 * @property MovementOrigin $origin
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $user_name
 * @property CarbonImmutable $occurred_at
 * @property-read ProductVariant $variant
 */
class InventoryCostChange extends Model
{
    /** Custo médio já formado por recebimentos. */
    public const SOURCE_AVERAGE = 'average';

    /** Custo cadastrado do produto, usado uma única vez para valorizar o saldo anterior ao primeiro recebimento. */
    public const SOURCE_REGISTERED = 'registered';

    /** Não havia saldo ou custo conhecido. */
    public const SOURCE_NONE = 'none';

    public $timestamps = false;

    protected $fillable = ['company_id', 'product_variant_id', 'inventory_movement_id', 'previous_quantity', 'previous_cost', 'previous_cost_source', 'entry_value', 'new_quantity', 'new_cost', 'origin', 'reference_type', 'reference_id', 'user_id', 'user_name', 'occurred_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico de custos é imutável.'));
        static::deleting(fn () => throw new LogicException('O histórico de custos não pode ser excluído.'));
    }

    protected function casts(): array
    {
        return [
            'origin' => MovementOrigin::class,
            'previous_quantity' => 'decimal:4',
            'previous_cost' => 'decimal:6',
            'entry_value' => 'decimal:2',
            'new_quantity' => 'decimal:4',
            'new_cost' => 'decimal:6',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
