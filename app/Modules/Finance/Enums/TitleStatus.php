<?php

namespace App\Modules\Finance\Enums;

enum TitleStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
    case Renegotiated = 'renegotiated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Partial => 'Parcialmente liquidado',
            self::Settled => 'Liquidado',
            self::Cancelled => 'Cancelado',
            self::Renegotiated => 'Renegociado',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Partial;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
