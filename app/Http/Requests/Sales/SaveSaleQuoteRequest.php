<?php

namespace App\Http\Requests\Sales;

use Illuminate\Contracts\Validation\ValidationRule;

class SaveSaleQuoteRequest extends SaleDocumentRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->commonRules(),
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'valid_until.after_or_equal' => 'A validade não pode ser anterior à emissão.'];
    }
}
