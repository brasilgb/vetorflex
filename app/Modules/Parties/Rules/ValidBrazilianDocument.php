<?php

namespace App\Modules\Parties\Rules;

use App\Modules\Parties\Support\BrazilianDocument;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidBrazilianDocument implements ValidationRule
{
    public function __construct(private ?string $partyType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $document = BrazilianDocument::normalize(is_scalar($value) ? (string) $value : null);

        if ($document === null) {
            return;
        }

        if ($this->partyType === 'company') {
            if (! BrazilianDocument::isCnpj($document)) {
                $fail('O CNPJ informado é inválido.');
            }

            return;
        }

        if (! BrazilianDocument::isCpf($document)) {
            $fail('O CPF informado é inválido.');
        }
    }
}
