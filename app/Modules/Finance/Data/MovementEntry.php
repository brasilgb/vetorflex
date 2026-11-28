<?php

namespace App\Modules\Finance\Data;

use App\Models\User;
use App\Modules\Finance\Enums\MovementType;

/**
 * Pedido de lançamento no livro financeiro. `amount` tem sinal: positivo entra, negativo sai.
 */
readonly class MovementEntry
{
    public function __construct(
        public int $companyId,
        public int $accountId,
        public MovementType $type,
        public string $amount,
        public string $occurredAt,
        public string $description,
        public string $idempotencyKey,
        public bool $isOperational = false,
        public ?int $categoryId = null,
        public ?int $settlementId = null,
        public ?int $transferId = null,
        public ?string $method = null,
        public ?User $user = null,
        public ?int $reversalOfId = null,
    ) {}
}
