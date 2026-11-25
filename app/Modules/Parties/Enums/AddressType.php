<?php

namespace App\Modules\Parties\Enums;

enum AddressType: string
{
    case Main = 'main';
    case Commercial = 'commercial';
    case Residential = 'residential';
    case Billing = 'billing';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Principal',
            self::Commercial => 'Comercial',
            self::Residential => 'Residencial',
            self::Billing => 'Cobrança',
            self::Delivery => 'Entrega',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
