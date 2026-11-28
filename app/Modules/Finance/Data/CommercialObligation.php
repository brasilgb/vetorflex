<?php

namespace App\Modules\Finance\Data;

use App\Modules\Finance\Enums\TitleKind;

/**
 * Obrigação de um documento comercial (venda ou compra), no formato neutro que o Financeiro concilia.
 * `target` é o valor que continua devido depois de cancelamentos, devoluções e saldos encerrados.
 */
readonly class CommercialObligation
{
    /**
     * @param  list<array{key: string, due_date: string, amount: string}>  $installments
     */
    public function __construct(
        public int $companyId,
        public TitleKind $kind,
        public string $sourceType,
        public int $sourceId,
        public string $label,
        public ?int $partyId,
        public ?string $partyName,
        public string $documentDate,
        public string $amount,
        public string $target,
        public bool $isCancelled,
        public array $installments,
        public string $baseKey,
    ) {}
}
