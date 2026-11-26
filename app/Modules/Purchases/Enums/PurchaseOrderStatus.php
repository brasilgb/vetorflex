<?php

namespace App\Modules\Purchases\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Confirmed => 'Confirmado',
            self::PartiallyReceived => 'Parcialmente recebido',
            self::Received => 'Recebido',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * O retorno de um estado recebido para outro anterior só ocorre por estorno de recebimento.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::PartiallyReceived, self::Received, self::Cancelled],
            self::PartiallyReceived => [self::Confirmed, self::Received],
            self::Received => [self::Confirmed, self::PartiallyReceived],
            self::Cancelled => [],
        }, true);
    }

    public function isPending(): bool
    {
        return $this === self::Confirmed || $this === self::PartiallyReceived;
    }

    /** @return list<self> Estados que representam uma compra efetivada. */
    public static function effective(): array
    {
        return [self::Confirmed, self::PartiallyReceived, self::Received];
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
