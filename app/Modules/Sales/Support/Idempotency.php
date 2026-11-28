<?php

namespace App\Modules\Sales\Support;

use Illuminate\Support\Arr;

/**
 * Hash do conteúdo de uma requisição: repetir a chave com o mesmo conteúdo devolve o documento já criado;
 * com conteúdo diferente, a requisição é recusada.
 */
class Idempotency
{
    /** @param array<string, mixed> $data */
    public static function hash(array $data): string
    {
        return hash('sha256', (string) json_encode(Arr::sortRecursive(Arr::except($data, ['idempotency_key']))));
    }
}
