<?php

namespace App\Modules\Pos\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Venda concluída no caixa. Leva os pagamentos informados pelo operador para que outros módulos (Financeiro) reajam
 * sem ler as tabelas do PDV. Publicado somente após a confirmação da transação.
 */
class PosSaleCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * `account_id` é a conta financeira configurada no PDV para a forma de pagamento, quando houver.
     *
     * @param  list<array{sequence: int, schedule_sequence: int|null, method: string, amount: string, is_received: bool, date: string, account_id: int|null}>  $payments
     */
    public function __construct(
        public int $companyId,
        public int $posSaleId,
        public int $saleOrderId,
        public array $payments,
        public ?int $userId,
    ) {}
}
