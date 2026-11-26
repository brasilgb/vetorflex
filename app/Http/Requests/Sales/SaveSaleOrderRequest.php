<?php

namespace App\Http\Requests\Sales;

use App\Modules\Sales\Enums\DeliveryMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Pedido de venda. A venda rápida usa as mesmas regras, com confirmação, atendimento e conclusão imediatos.
 */
class SaveSaleOrderRequest extends SaleDocumentRequest
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
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'delivery_method' => ['required', Rule::enum(DeliveryMethod::class)],
            'delivery_address_id' => ['nullable', 'integer'],
            'delivery_address_text' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'expected_date.after_or_equal' => 'A previsão não pode ser anterior à venda.'];
    }
}
