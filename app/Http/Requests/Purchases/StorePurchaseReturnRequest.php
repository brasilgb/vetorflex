<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseReturnRequest extends FormRequest
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
            'returned_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($this->attributes->get('company')?->timezone)->addDay()->format('Y-m-d')],
            'reason' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.SavePurchaseOrderRequest::MAXIMUM_ITEMS],
            'items.*.purchase_receipt_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'returned_at.required' => 'Informe a data da devolução.',
            'returned_at.before_or_equal' => 'A data da devolução não pode estar no futuro.',
            'reason.required' => 'Informe o motivo da devolução.',
            'items.*.quantity.decimal' => 'Informe uma quantidade com até 4 casas decimais.',
            'items.*.quantity.min' => 'A quantidade não pode ser negativa.',
        ];
    }
}
