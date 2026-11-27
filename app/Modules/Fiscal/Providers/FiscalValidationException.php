<?php

namespace App\Modules\Fiscal\Providers;

use Illuminate\Validation\ValidationException;

/**
 * Dados fiscais ausentes ou inválidos para o documento. Nada é presumido: a lista diz exatamente o que corrigir.
 */
class FiscalValidationException extends ValidationException
{
    /** @param list<string> $problems */
    public static function problems(array $problems): self
    {
        $exception = self::withMessages(['fiscal' => $problems]);

        return new self($exception->validator);
    }
}
