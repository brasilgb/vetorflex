<?php

namespace App\Http\Requests\Purchases;

use App\Modules\Parties\Rules\ValidBrazilianDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Compra já realizada: o fornecedor pode ser selecionado ou cadastrado de forma simplificada no mesmo envio.
 */
class StoreQuickPurchaseRequest extends SavePurchaseOrderRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'supplier_profile_id' => ['nullable', 'required_without:new_supplier', 'integer', Rule::exists('supplier_profiles', 'id')->where('company_id', $this->attributes->get('company')->id)],
            'new_supplier' => ['nullable', 'array', 'prohibits:supplier_profile_id'],
            'new_supplier.type' => ['required_with:new_supplier', 'in:individual,company'],
            'new_supplier.name' => ['required_with:new_supplier', 'string', 'max:255'],
            'new_supplier.trade_name' => ['nullable', 'string', 'max:255'],
            'new_supplier.document' => ['nullable', 'string', 'max:20', new ValidBrazilianDocument($this->string('new_supplier.type')->value())],
            'new_supplier.email' => ['nullable', 'email', 'max:255'],
            'new_supplier.phone' => ['nullable', 'string', 'max:30'],
            'new_supplier.whatsapp' => ['nullable', 'string', 'max:30'],
            'new_supplier.link_existing' => ['sometimes', 'boolean'],
            'document_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'supplier_profile_id.required_without' => 'Selecione ou cadastre o fornecedor.',
            'new_supplier.name.required_with' => 'Informe o nome do novo fornecedor.',
        ];
    }
}
