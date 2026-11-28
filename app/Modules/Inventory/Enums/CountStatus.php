<?php

namespace App\Modules\Inventory\Enums;

enum CountStatus: string
{
    case Open = 'open';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em contagem',
            self::Approved => 'Aprovado',
            self::Cancelled => 'Cancelado',
        };
    }
}
