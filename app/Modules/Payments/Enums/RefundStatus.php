<?php

namespace App\Modules\Payments\Enums;

enum RefundStatus: string
{
    case Processing = 'processing';
    case Refunded = 'refunded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'Em processamento',
            self::Refunded => 'Devolvido',
            self::Failed => 'Não realizado',
        };
    }
}
