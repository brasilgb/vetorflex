<?php

namespace App\Modules\Sales\Enums;

/**
 * Situação comercial e de atendimento do pedido. A situação financeira pertence ao Financeiro:
 * uma venda concluída não é considerada paga.
 */
enum SaleOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Confirmed => 'Confirmado',
            self::PartiallyFulfilled => 'Parcialmente atendido',
            self::Fulfilled => 'Atendido',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * O retorno de um estado atendido para outro anterior só ocorre por estorno técnico de atendimento.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::PartiallyFulfilled, self::Fulfilled, self::Cancelled],
            self::PartiallyFulfilled => [self::Confirmed, self::Fulfilled],
            self::Fulfilled => [self::Confirmed, self::PartiallyFulfilled, self::Completed],
            self::Completed, self::Cancelled => [],
        }, true);
    }

    public function isPending(): bool
    {
        return $this === self::Confirmed || $this === self::PartiallyFulfilled;
    }

    public function acceptsReturns(): bool
    {
        return in_array($this, [self::PartiallyFulfilled, self::Fulfilled, self::Completed], true);
    }

    /** @return list<self> Estados que representam uma venda efetivada. */
    public static function effective(): array
    {
        return [self::Confirmed, self::PartiallyFulfilled, self::Fulfilled, self::Completed];
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
