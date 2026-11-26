<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Reserva de estoque por documento de origem, reutilizável por Vendas, PDV e lojas virtuais.
 *
 * A reserva tem semântica de "definir": cada chamada informa a quantidade que o documento deve manter reservada,
 * o que a torna idempotente. A unidade comercializável é bloqueada antes do saldo, como em InventoryService;
 * documentos com vários itens devem chamá-la em ordem crescente de unidade comercializável.
 *
 * O estoque negativo não é permitido: só se reserva o que está disponível (saldo menos reservas de outros documentos).
 */
class InventoryReservationService
{
    private const SCALE = 4;

    public function reserve(int $companyId, int $variantId, string $referenceType, string $referenceId, string $quantity): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A reserva de estoque exige uma transação aberta.');
        }

        $target = BigDecimal::of($quantity)->toScale(self::SCALE);

        if ($target->isNegative()) {
            throw new LogicException('A quantidade reservada não pode ser negativa.');
        }

        $variant = ProductVariant::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($variantId);
        InventoryBalance::query()->insertOrIgnore([
            'company_id' => $companyId,
            'product_variant_id' => $variantId,
            'quantity' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $balance = InventoryBalance::query()->where('product_variant_id', $variantId)->lockForUpdate()->firstOrFail();
        $reservation = InventoryReservation::query()->where('company_id', $companyId)
            ->where('reference_type', $referenceType)->where('reference_id', $referenceId)->lockForUpdate()->first();
        $current = BigDecimal::of($reservation->quantity ?? '0');

        if ($target->isEqualTo($current)) {
            return;
        }

        $reservedByOthers = BigDecimal::of($balance->reserved_quantity)->minus($current);
        $available = BigDecimal::of($balance->quantity)->minus($reservedByOthers);

        if ($target->isGreaterThan($current) && $target->isGreaterThan($available)) {
            $name = $variant->displayName();

            throw ValidationException::withMessages(['items' => "Estoque insuficiente para reservar \"{$name}\". Disponível: {$this->format($available->isNegative() ? BigDecimal::zero() : $available)}."]);
        }

        if ($reservation === null) {
            $reservation = new InventoryReservation;
            $reservation->forceFill(['company_id' => $companyId, 'product_variant_id' => $variantId, 'reference_type' => $referenceType, 'reference_id' => $referenceId]);
        }

        $reservation->forceFill(['quantity' => (string) $target])->save();
        $balance->forceFill(['reserved_quantity' => (string) $reservedByOthers->plus($target)->toScale(self::SCALE)])->save();
    }

    public function release(int $companyId, int $variantId, string $referenceType, string $referenceId): void
    {
        $this->reserve($companyId, $variantId, $referenceType, $referenceId, '0');
    }

    public function reservedFor(int $companyId, string $referenceType, string $referenceId): string
    {
        return InventoryReservation::query()->where('company_id', $companyId)->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)->value('quantity') ?? '0.0000';
    }

    /**
     * Saldo físico, reservado e disponível de cada unidade comercializável.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{quantity: string, reserved: string, available: string}>
     */
    public function availabilityOf(int $companyId, array $variantIds): array
    {
        $balances = InventoryBalance::query()->where('company_id', $companyId)->whereIn('product_variant_id', $variantIds)->get()->keyBy('product_variant_id');
        $result = [];

        foreach ($variantIds as $variantId) {
            $balance = $balances->get($variantId);
            $quantity = BigDecimal::of($balance->quantity ?? '0')->toScale(self::SCALE);
            $reserved = BigDecimal::of($balance->reserved_quantity ?? '0')->toScale(self::SCALE);
            $result[$variantId] = ['quantity' => (string) $quantity, 'reserved' => (string) $reserved, 'available' => (string) $quantity->minus($reserved)];
        }

        return $result;
    }

    private function format(BigDecimal $value): string
    {
        return str_replace('.', ',', (string) $value->strippedOfTrailingZeros());
    }
}
