<?php

namespace App\Modules\Sales\Enums;

enum DeliveryMethod: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Retirada no estabelecimento',
            self::Delivery => 'Entrega ao cliente',
            self::Service => 'Atendimento de serviço',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $method): array => ['value' => $method->value, 'label' => $method->label()], self::cases());
    }
}
