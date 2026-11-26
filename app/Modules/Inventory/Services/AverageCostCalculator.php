<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryCostChange;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Custo médio ponderado móvel. É chamado por InventoryService já com a unidade comercializável e o saldo bloqueados.
 *
 * Somente entradas com valor de aquisição mudam o custo. Saídas, ajustes e entradas sem valor o preservam.
 */
class AverageCostCalculator
{
    public const SCALE = 6;

    /** Retorna o custo médio resultante e registra a alteração, quando houver. */
    public function apply(ProductVariant $variant, InventoryMovement $movement, ?string $currentCost, ?string $entryValue, ?InventoryMovement $reversed): ?string
    {
        if ($movement->type === MovementType::Reversal && $reversed !== null) {
            return $this->applyReversal($movement, $currentCost, $reversed);
        }

        if ($movement->type !== MovementType::Entry || $entryValue === null) {
            return $currentCost;
        }

        $previousQuantity = BigDecimal::of($movement->balance_before);
        $source = $this->previousCostSource($variant, $currentCost, $previousQuantity);
        $previousCost = match ($source) {
            InventoryCostChange::SOURCE_AVERAGE => $currentCost,
            InventoryCostChange::SOURCE_REGISTERED => $variant->cost_price,
            default => null,
        };
        // Sem custo conhecido para o saldo anterior, ele assume o custo desta aquisição.
        $newCost = $previousCost === null || $previousQuantity->isZero()
            ? BigDecimal::of($entryValue)->dividedBy($movement->quantity, self::SCALE, RoundingMode::HalfUp)
            : $previousQuantity->multipliedBy($previousCost)->plus($entryValue)->dividedBy($movement->balance_after, self::SCALE, RoundingMode::HalfUp);

        return $this->record($movement, $previousCost, $source, $entryValue, (string) $newCost);
    }

    /**
     * O estorno só restaura o custo anterior quando desfaz a última movimentação do item. Havendo movimentações
     * posteriores, o custo vigente é mantido, pois elas já o utilizaram.
     */
    private function applyReversal(InventoryMovement $movement, ?string $currentCost, InventoryMovement $reversed): ?string
    {
        $change = InventoryCostChange::query()->where('inventory_movement_id', $reversed->id)->first();
        $hasLaterMovement = InventoryMovement::query()->where('product_variant_id', $movement->product_variant_id)
            ->where('id', '>', $reversed->id)->where('id', '<', $movement->id)->lockForUpdate()->exists();

        if ($change === null || $hasLaterMovement) {
            return $currentCost;
        }

        $restoredCost = $change->previous_cost_source === InventoryCostChange::SOURCE_AVERAGE ? $change->previous_cost : null;

        return $this->record($movement, $currentCost, InventoryCostChange::SOURCE_AVERAGE, null, $restoredCost);
    }

    private function previousCostSource(ProductVariant $variant, ?string $currentCost, BigDecimal $previousQuantity): string
    {
        return match (true) {
            $currentCost !== null => InventoryCostChange::SOURCE_AVERAGE,
            $variant->cost_price !== null && ! $previousQuantity->isZero() => InventoryCostChange::SOURCE_REGISTERED,
            default => InventoryCostChange::SOURCE_NONE,
        };
    }

    private function record(InventoryMovement $movement, ?string $previousCost, string $source, ?string $entryValue, ?string $newCost): ?string
    {
        InventoryCostChange::create([
            'company_id' => $movement->company_id,
            'product_variant_id' => $movement->product_variant_id,
            'inventory_movement_id' => $movement->id,
            'previous_quantity' => $movement->balance_before,
            'previous_cost' => $previousCost,
            'previous_cost_source' => $source,
            'entry_value' => $entryValue,
            'new_quantity' => $movement->balance_after,
            'new_cost' => $newCost,
            'origin' => $movement->origin,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'user_id' => $movement->user_id,
            'user_name' => $movement->user_name,
            'occurred_at' => $movement->occurred_at,
        ]);

        return $newCost;
    }
}
