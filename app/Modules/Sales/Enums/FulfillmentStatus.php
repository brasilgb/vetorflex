<?php

namespace App\Modules\Sales\Enums;

enum FulfillmentStatus: string
{
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Registrado',
            self::Reversed => 'Estornado',
        };
    }
}
