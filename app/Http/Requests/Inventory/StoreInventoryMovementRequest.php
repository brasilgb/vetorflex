<?php

namespace App\Http\Requests\Inventory;

use App\Modules\Inventory\Enums\MovementType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryMovementRequest extends FormRequest
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
        $company = $this->attributes->get('company');

        return [
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('company_id', $company->id)],
            'type' => ['required', Rule::enum(MovementType::class)->except(MovementType::Reversal)],
            'quantity' => ['required', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'reason' => ['nullable', 'required_if:type,adjustment', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_variant_id.required' => 'Selecione o item.',
            'product_variant_id.exists' => 'O item não pertence a esta empresa.',
            'quantity.required' => 'Informe a quantidade.',
            'quantity.decimal' => 'Informe uma quantidade válida, com até 4 casas decimais.',
            'quantity.min' => 'A quantidade não pode ser negativa.',
            'reason.required_if' => 'Informe o motivo do ajuste.',
        ];
    }
}
