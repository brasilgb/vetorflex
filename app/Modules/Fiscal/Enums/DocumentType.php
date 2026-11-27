<?php

namespace App\Modules\Fiscal\Enums;

enum DocumentType: string
{
    case Nfse = 'nfse';
    case Nfe = 'nfe';
    case Nfce = 'nfce';

    public function label(): string
    {
        return match ($this) {
            self::Nfse => 'NFS-e — Nota Fiscal de Serviço',
            self::Nfe => 'NF-e — Nota Fiscal de mercadorias',
            self::Nfce => 'NFC-e — Nota Fiscal de Consumidor',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Nfse => 'NFS-e',
            self::Nfe => 'NF-e',
            self::Nfce => 'NFC-e',
        };
    }

    public function isService(): bool
    {
        return $this === self::Nfse;
    }
}
