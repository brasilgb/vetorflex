<?php

namespace App\Modules\Sales\Enums;

enum SaleQuoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Issued => 'Emitido',
            self::Approved => 'Aprovado',
            self::Rejected => 'Recusado',
            self::Cancelled => 'Cancelado',
            self::Expired => 'Expirado',
            self::Converted => 'Convertido em venda',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Issued, self::Cancelled],
            self::Issued => [self::Approved, self::Rejected, self::Cancelled, self::Expired],
            self::Approved => [self::Converted, self::Cancelled],
            self::Rejected, self::Cancelled, self::Expired, self::Converted => [],
        }, true);
    }

    /** Situações em que a validade ainda importa. */
    public function canExpire(): bool
    {
        return $this === self::Issued;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
