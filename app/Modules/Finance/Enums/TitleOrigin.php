<?php

namespace App\Modules\Finance\Enums;

enum TitleOrigin: string
{
    case Manual = 'manual';
    case Sale = 'sale';
    case Purchase = 'purchase';
    case Recurrence = 'recurrence';
    case Renegotiation = 'renegotiation';
    case SaleRefund = 'sale_refund';
    case PurchaseRefund = 'purchase_refund';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Lançamento manual',
            self::Sale => 'Venda',
            self::Purchase => 'Compra',
            self::Recurrence => 'Recorrência',
            self::Renegotiation => 'Renegociação',
            self::SaleRefund => 'Restituição de venda',
            self::PurchaseRefund => 'Restituição de compra',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
