<?php

namespace App\Modules\Fiscal\Providers;

use RuntimeException;

/**
 * Falha de comunicação ou recusa do provedor fiscal, com mensagem segura para exibir (nunca chaves, senhas ou corpo
 * bruto). Transitória (sem resposta, 429, 5xx): a operação pode ter sido processada, então consultar antes de agir.
 */
class FiscalProviderException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(string $message, public readonly ?int $status = null, public readonly array $errors = [])
    {
        parent::__construct($message, $status ?? 0);
    }

    public function isTransient(): bool
    {
        return $this->status === null || $this->status === 429 || $this->status >= 500;
    }
}
