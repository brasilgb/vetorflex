<?php

namespace App\Modules\Finance\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Checking = 'checking';
    case Digital = 'digital';
    case Savings = 'savings';
    case Wallet = 'wallet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Caixa físico',
            self::Checking => 'Conta corrente',
            self::Digital => 'Conta digital',
            self::Savings => 'Poupança',
            self::Wallet => 'Carteira',
            self::Other => 'Outra conta',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
