<?php

namespace App\Modules\Purchases\Enums;

enum ReceiptStatus: string
{
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmado',
            self::Reversed => 'Estornado',
        };
    }
}
