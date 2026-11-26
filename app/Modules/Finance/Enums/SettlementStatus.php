<?php

namespace App\Modules\Finance\Enums;

enum SettlementStatus: string
{
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmada',
            self::Reversed => 'Estornada',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
