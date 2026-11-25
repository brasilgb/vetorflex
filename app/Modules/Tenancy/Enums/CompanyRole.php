<?php

namespace App\Modules\Tenancy\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Proprietário',
            self::Administrator => 'Administrador',
            self::Operator => 'Operador',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $role): array => ['value' => $role->value, 'label' => $role->label()], self::cases());
    }
}
