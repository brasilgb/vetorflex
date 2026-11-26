<?php

namespace App\Modules\Finance\Enums;

enum MovementType: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::Exit => 'Saída',
            self::Transfer => 'Transferência',
            self::Adjustment => 'Ajuste',
            self::Reversal => 'Estorno',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
