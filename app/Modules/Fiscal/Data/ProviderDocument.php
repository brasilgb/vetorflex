<?php

namespace App\Modules\Fiscal\Data;

use App\Modules\Fiscal\Enums\FiscalStatus;
use Carbon\CarbonImmutable;

/**
 * Documento como o provedor o vê. `status` nulo indica um estado do provedor sem correspondência (ignorado).
 */
readonly class ProviderDocument
{
    /** @param array<string, mixed> $summary Resumo para suporte, sem dados pessoais completos. */
    public function __construct(
        public ?string $reference,
        public string $providerStatus,
        public ?FiscalStatus $status,
        public ?string $number = null,
        public ?string $series = null,
        public ?string $accessKey = null,
        public ?string $protocol = null,
        public ?CarbonImmutable $issuedAt = null,
        public ?CarbonImmutable $cancelledAt = null,
        public ?string $errorMessage = null,
        public ?string $environment = null,
        public ?string $issuerTaxNumber = null,
        public array $summary = [],
    ) {}
}
