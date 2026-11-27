<?php

namespace App\Modules\Pos\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sangria, suprimento ou ajuste registrado na gaveta. Publicado somente após a confirmação da transação.
 */
class PosCashMovementRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $movementId,
        public string $type,
        public string $amount,
        public string $date,
        public string $reason,
        public ?int $userId,
        public ?int $fromAccountId = null,
        public ?int $toAccountId = null,
    ) {}
}
