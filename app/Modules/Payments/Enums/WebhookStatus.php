<?php

namespace App\Modules\Payments\Enums;

enum WebhookStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Na fila',
            self::Processed => 'Processado',
            self::Ignored => 'Ignorado',
            self::Failed => 'Falha',
        };
    }
}
