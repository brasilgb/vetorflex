<?php

namespace App\Modules\Sales\Enums;

/**
 * Origem do pedido. PDV, lojas virtuais e integrações usarão os mesmos serviços quando forem implementados.
 */
enum SaleOrigin: string
{
    case Direct = 'direct';
    case Quote = 'quote';
    case PointOfSale = 'pos';
    case Ecommerce = 'ecommerce';
    case Integration = 'integration';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Venda direta',
            self::Quote => 'Orçamento',
            self::PointOfSale => 'PDV',
            self::Ecommerce => 'Loja virtual',
            self::Integration => 'Integração',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $origin): array => ['value' => $origin->value, 'label' => $origin->label()], self::cases());
    }
}
