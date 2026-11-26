<?php

namespace App\Http\Requests\Purchases;

use App\Modules\Purchases\Models\SupplierProduct;
use App\Modules\Purchases\Models\SupplierProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O último custo não é informado: ele vem sempre do último recebimento confirmado.
 */
class SaveSupplierProductRequest extends FormRequest
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
        $link = $this->route('link');
        $supplier = $this->route('supplier');

        return [
            'product_variant_id' => [
                $link instanceof SupplierProduct ? 'prohibited' : 'required', 'integer',
                Rule::exists('product_variants', 'id')->where('company_id', $company->id),
                Rule::unique('supplier_products', 'product_variant_id')->where('supplier_profile_id', $supplier instanceof SupplierProfile ? $supplier->id : 0),
            ],
            'supplier_code' => ['nullable', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:999'],
            'minimum_quantity' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'is_preferred' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_variant_id.required' => 'Selecione o item.',
            'product_variant_id.exists' => 'O item não pertence a esta empresa.',
            'product_variant_id.unique' => 'O item já está vinculado a este fornecedor.',
        ];
    }
}
