<?php

namespace App\Modules\Inventory\Data;

use App\Models\User;
use App\Modules\Inventory\Enums\MovementOrigin;

/**
 * Pedido de movimentação. A quantidade é sempre positiva; o sinal é definido pelo serviço.
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
    ) {}
}
