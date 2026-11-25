<?php

namespace App\Modules\Products\Enums;

enum BarcodeType: string
{
    case Gtin = 'gtin';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Gtin => 'GTIN/EAN/UPC',
            self::Internal => 'Código interno',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
