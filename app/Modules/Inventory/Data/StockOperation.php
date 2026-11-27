<?php

namespace App\Modules\Inventory\Data;

use App\Models\User;
use App\Modules\Inventory\Enums\MovementOrigin;

/**
 * Pedido de movimentação. A quantidade é sempre positiva; o sinal é definido pelo serviço.
 * `warehouseId` é o depósito; sem ele, vale o depósito principal da empresa.
 * `entryValue` é o valor total da aquisição: somente entradas que o informam alteram o custo médio.
 */
readonly class StockOperation
{
    public function __construct(
        public int $companyId,
        public int $variantId,
        public string $quantity,
        public MovementOrigin $origin = MovementOrigin::Manual,
        public ?User $user = null,
        public ?string $reason = null,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?string $idempotencyKey = null,
        public ?string $unitCost = null,
        public ?string $entryValue = null,
        public ?int $warehouseId = null,
    ) {}
}
