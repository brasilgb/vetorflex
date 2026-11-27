<?php

namespace App\Modules\Payments\Providers;

use RuntimeException;

/**
 * Falha de comunicação ou recusa do provedor, com mensagem compreensível e sem segredos. `isRetryable` distingue
 * falhas temporárias (rede, limite de requisições, indisponibilidade) de recusas definitivas.
 */
class ProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $isRetryable = false, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
