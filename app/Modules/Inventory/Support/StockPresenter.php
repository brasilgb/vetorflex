<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Inventory\Models\InventoryWarehouse;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;

/**
 * Formato dos registros do Estoque para as telas. Quantidades seguem como texto decimal; custo somente quando
 * permitido.
 */
class StockPresenter
{
    /** @return array<string, mixed> */
    public function item(ProductVariant $variant, bool $canViewCost, ?string $lastMovementAt = null): array
    {
        $balance = self::quantity($variant->getAttribute('balance'));
        $reserved = self::quantity($variant->getAttribute('reserved'));
        $minimum = (string) $variant->minimum_stock;

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'name' => $variant->displayName(),
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'internal_code' => $variant->product->internal_code,
            'category' => $variant->product->category?->name,
            'unit' => ['code' => $variant->product->unit->code, 'allows_fraction' => $variant->product->unit->allows_fraction],
            'balance' => $balance,
            'reserved' => $reserved,
            'available' => (string) BigDecimal::of($balance)->minus($reserved),
            'warehouse_quantity' => $variant->getAttribute('warehouse_quantity') === null ? null : self::quantity($variant->getAttribute('warehouse_quantity')),
            'minimum_stock' => $minimum,
            'maximum_stock' => $variant->maximum_stock,
            'is_low_stock' => BigDecimal::of($minimum)->isPositive() && BigDecimal::of($balance)->isLessThan($minimum),
            'is_above_max' => $variant->maximum_stock !== null && BigDecimal::of($balance)->isGreaterThan($variant->maximum_stock),
            'is_active' => $variant->is_active && $variant->product->is_active,
            'cost_price' => $canViewCost ? $variant->cost_price : null,
            'last_movement_at' => $lastMovementAt === null ? null : now()->parse($lastMovementAt, 'UTC')->toIso8601String(),
        ];
    }

    /** Quantidades agregadas no banco chegam com escala variável (SQLite, MySQL); a tela recebe sempre 4 casas. */
    private static function quantity(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(4);
    }

    /** @return array<string, mixed> */
    public function movement(InventoryMovement $movement, bool $canViewCost): array
    {
        return [
            'id' => $movement->id,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'origin' => $movement->origin->value,
            'origin_label' => $movement->origin->label(),
            'quantity' => $movement->quantity,
            'balance_after' => $movement->balance_after,
            'warehouse' => $movement->warehouse?->name,
            'warehouse_balance_after' => $movement->warehouse_balance_after,
            'item' => $movement->variant->displayName(),
            'variant_id' => $movement->product_variant_id,
            'sku' => $movement->variant->sku,
            'unit' => $movement->variant->product->unit->code,
            'user_name' => $movement->user_name,
            'reason' => $movement->reason,
            'reference' => $movement->reference_type === null ? null : "{$movement->reference_type}:{$movement->reference_id}",
            'unit_cost' => $canViewCost ? $movement->unit_cost : null,
            'is_reversed' => $movement->reversal !== null,
            'is_reversible' => ! in_array($movement->type, [MovementType::Reversal, MovementType::TransferOut, MovementType::TransferIn], true)
                && $movement->reversal === null && ! in_array($movement->origin, [MovementOrigin::Purchase, MovementOrigin::Sale, MovementOrigin::Count], true),
        ];
    }

    /** @return array<string, mixed> */
    public function warehouse(InventoryWarehouse $warehouse): array
    {
        return $warehouse->only(['id', 'name', 'code', 'location', 'is_default', 'is_active']);
    }

    /** @return array<string, mixed> */
    public function transfer(InventoryTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'number' => $transfer->number,
            'from' => $transfer->from->name,
            'to' => $transfer->to->name,
            'user_name' => $transfer->user_name,
            'reason' => $transfer->reason,
            'transferred_at' => $transfer->transferred_at->toIso8601String(),
            'items' => $transfer->movements->where('type', MovementType::TransferIn)
                ->map(fn (InventoryMovement $movement): array => ['item' => $movement->variant->displayName(), 'quantity' => $movement->quantity, 'unit' => $movement->variant->product->unit->code])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function count(InventoryCount $count): array
    {
        return [
            'id' => $count->id,
            'number' => $count->number,
            'warehouse' => $count->warehouse->name,
            'status' => $count->status->value,
            'status_label' => $count->status->label(),
            'description' => $count->description,
            'created_by_name' => $count->created_by_name,
            'created_at' => $count->created_at?->toIso8601String(),
            'approved_by_name' => $count->approved_by_name,
            'approved_at' => $count->approved_at?->toIso8601String(),
            'cancelled_by_name' => $count->cancelled_by_name,
            'cancellation_reason' => $count->cancellation_reason,
            'items_count' => $count->getAttribute('items_count'),
            'counted_count' => $count->getAttribute('counted_count'),
        ];
    }
}
