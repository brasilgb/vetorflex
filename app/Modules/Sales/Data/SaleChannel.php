<?php

namespace App\Modules\Sales\Data;

use App\Modules\Sales\Enums\SaleOrigin;

/**
 * Canal que origina a venda, definido pelo servidor (nunca pela requisição). PDV, lojas virtuais e integrações usam o
 * mesmo serviço comercial informando a origem, uma referência legível (por exemplo, terminal e sessão) e, quando o
 * canal tem configuração própria, se aceita venda sem identificação do cliente.
 */
readonly class SaleChannel
{
    public function __construct(
        public SaleOrigin $origin = SaleOrigin::Direct,
        public ?string $reference = null,
        public ?bool $allowsAnonymousCustomer = null,
    ) {}
}
