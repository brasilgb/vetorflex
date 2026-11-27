<?php

namespace App\Http\Requests\Pos;

use App\Modules\Core\Commerce\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Devolução de uma venda do caixa. Sem itens, devolve tudo o que ainda não foi devolvido.
 */
class PosReturnRequest extends FormRequest
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
            'items' => ['nullable', 'array', 'list', 'max:300'],
            'items.*.sale_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'restock' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:400'],
            'refund_method' => ['required', Rule::enum(PaymentMethod::class)],
            'pos_session_id' => ['nullable', 'integer'],
            'authorization' => ['nullable', 'array'],
            'authorization.email' => ['nullable', 'string', 'max:255'],
            'authorization.password' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Informe o motivo da devolução.', 'refund_method.required' => 'Informe a forma de restituição.'];
    }
}
