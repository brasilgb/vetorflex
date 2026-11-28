<?php

namespace App\Http\Requests\Sales;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro simplificado de produto ou serviço durante a venda, no catálogo compartilhado.
 */
class StoreSaleItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:simple,service'],
            'sale_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('product_variants', 'sku')->where('company_id', $company?->id)],
            'unit_code' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.required' => 'Informe o nome do item.', 'sale_price.required' => 'Informe o preço de venda.', 'sku.unique' => 'Este SKU já está em uso.'];
    }
}
