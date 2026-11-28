<?php

namespace App\Modules\Payments\Enums;

enum ChargeOrigin: string
{
    case Manual = 'manual';
    case Sale = 'sale';
    case Finance = 'finance';
    case Pos = 'pos';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Avulsa',
            self::Sale => 'Venda',
            self::Finance => 'Conta a receber',
            self::Pos => 'PDV',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $origin): array => ['value' => $origin->value, 'label' => $origin->label()], self::cases());
    }
}
