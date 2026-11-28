<?php

namespace App\Modules\Pos\Enums;

/**
 * Situação do atendimento no caixa. Suspenso e cancelado não têm venda comercial; concluído aponta para a venda.
 */
enum PosSaleStatus: string
{
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Suspended => 'Suspensa',
            self::Completed => 'Concluída',
            self::Cancelled => 'Cancelada',
        };
    }
}
