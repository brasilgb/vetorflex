<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
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
        return [
            'received_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($this->attributes->get('company')?->timezone)->addDay()->format('Y-m-d')],
            'document_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.SavePurchaseOrderRequest::MAXIMUM_ITEMS],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'items.*.divergence' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'received_at.required' => 'Informe a data do recebimento.',
            'received_at.before_or_equal' => 'A data do recebimento não pode estar no futuro.',
            'items.*.quantity.decimal' => 'Informe uma quantidade com até 4 casas decimais.',
            'items.*.quantity.min' => 'A quantidade não pode ser negativa.',
        ];
    }
}
