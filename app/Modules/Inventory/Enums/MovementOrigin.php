<?php

namespace App\Modules\Inventory\Enums;

/**
 * Domínio que originou a movimentação. Canais externos são identificados no futuro domínio de integrações.
 */
enum MovementOrigin: string
{
    case Manual = 'manual';
    case Product = 'product';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Count = 'count';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Product => 'Cadastro de produto',
            self::Purchase => 'Compra',
            self::Sale => 'Venda',
            self::Count => 'Inventário',
        };
    }
}
