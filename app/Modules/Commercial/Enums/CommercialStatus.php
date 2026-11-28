<?php

namespace App\Modules\Commercial\Enums;

/**
 * Situação comercial da assinatura da empresa, distinta do acesso (`companies.status`, ativa ou suspensa pelo
 * RootAdmin). Não há cobrança automática: a contratação é registrada expressamente.
 */
enum CommercialStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Em teste',
            self::Active => 'Contratada',
            self::Expired => 'Teste encerrado',
        };
    }
}
