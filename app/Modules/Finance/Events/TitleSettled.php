<?php

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\FinanceTitle;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Baixa registrada ou estornada num título. Publicado somente após a confirmação da transação, para
 * integrações futuras (notificações, cobrança, PDV).
 */
class TitleSettled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public FinanceTitle $title) {}
}
