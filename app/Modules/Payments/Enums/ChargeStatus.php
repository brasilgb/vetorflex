<?php

namespace App\Modules\Payments\Enums;

/**
 * Estados internos da cobrança, independentes dos estados de cada provedor. "Vencido" é gravado pela conciliação
 * quando uma cobrança pendente passa do vencimento sem pagamento; "Expirado", quando o prazo do provedor termina.
 */
enum ChargeStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Paid => 'Pago',
            self::PartiallyPaid => 'Parcialmente pago',
            self::Overdue => 'Vencido',
            self::Cancelled => 'Cancelado',
            self::Expired => 'Expirado',
            self::Refunded => 'Estornado',
            self::Failed => 'Falha',
        };
    }

    /** Aguardando pagamento: ocupa a origem (uma cobrança aberta por venda ou título) e entra na conciliação. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Pending, self::Processing, self::PartiallyPaid, self::Overdue], true);
    }

    /** Pode receber pagamento confirmado pelo provedor (inclusive tardio, depois de vencida ou expirada). */
    public function acceptsPayment(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::PartiallyPaid, self::Overdue, self::Expired], true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
