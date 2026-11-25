<?php

namespace App\Modules\Products\Enums;

enum ProductType: string
{
    case Simple = 'simple';
    case Variable = 'variable';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Produto simples',
            self::Variable => 'Produto com variações',
            self::Service => 'Serviço',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
