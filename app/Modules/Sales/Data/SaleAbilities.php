<?php

namespace App\Modules\Sales\Data;

/**
 * O que o usuário pode definir num documento de venda. Valores que ele não pode definir são recalculados ou
 * preservados pelo servidor, nunca aceitos da requisição.
 */
readonly class SaleAbilities
{
    public function __construct(
        public bool $canChangePrices = false,
        public bool $canApplyDiscounts = false,
        public bool $canUseCustomItems = false,
    ) {}

    public static function full(): self
    {
        return new self(true, true, true);
    }
}
