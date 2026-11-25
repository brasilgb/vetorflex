<?php

namespace App\Modules\Inventory\Enums;

enum MovementType: string
{
    case Initial = 'initial';
    case Entry = 'entry';
    case Exit = 'exit';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Estoque inicial',
            self::Entry => 'Entrada',
            self::Exit => 'Saída',
            self::Adjustment => 'Ajuste',
            self::Reversal => 'Estorno',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
