<?php

namespace App\Modules\Fiscal\Enums;

enum FiscalOrigin: string
{
    case Manual = 'manual';
    case Sale = 'sale';
    case Pos = 'pos';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Avulsa',
            self::Sale => 'Venda',
            self::Pos => 'PDV',
            self::Finance => 'Conta a receber',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $origin): array => ['value' => $origin->value, 'label' => $origin->label()], self::cases());
    }
}
