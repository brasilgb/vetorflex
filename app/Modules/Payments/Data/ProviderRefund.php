<?php

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\RefundStatus;

/**
 * Devolução, como informada pelo provedor.
 */
readonly class ProviderRefund
{
    public function __construct(
        public string $id,
        public string $amount,
        public RefundStatus $status,
        public ?string $rtrId = null,
        public ?string $reason = null,
    ) {}
}
