<?php

namespace App\Modules\Payments\Enums;

enum AccountStatus: string
{
    case Incomplete = 'incomplete';
    case Untested = 'untested';
    case Active = 'active';
    case Failing = 'failing';

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => 'Configuração incompleta',
            self::Untested => 'Aguardando teste de conexão',
            self::Active => 'Conectada',
            self::Failing => 'Com falha',
        };
    }
}
