<?php

namespace App\Http\Requests\Finance;

use App\Modules\Core\Commerce\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Baixa de título: conta, data efetiva, forma, principal, juros, multa e desconto. O valor movimentado é calculado.
 */
class SettleFinanceTitleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $money = ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'];

        return [
            'finance_account_id' => ['required', 'integer'],
            'settled_at' => ['required', 'date_format:Y-m-d'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'principal' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'interest' => $money,
            'fine' => $money,
            'discount' => $money,
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'finance_account_id.required' => 'Selecione a conta financeira.',
            'settled_at.required' => 'Informe a data efetiva.',
            'principal.required' => 'Informe o valor baixado.',
            '*.regex' => 'Informe um valor válido, com até duas casas decimais.',
        ];
    }
}
