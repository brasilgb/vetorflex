<?php

namespace App\Http\Requests\Customers;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Rules\ValidBrazilianDocument;
use App\Modules\Parties\Support\BrazilianDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
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
            'type' => ['required', 'in:individual,company'],
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:20', new ValidBrazilianDocument($this->input('type'))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'state_registration' => ['nullable', 'string', 'max:30'],
            'municipal_registration' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'classification' => ['nullable', 'string', 'max:50'],
            'commercial_notes' => ['nullable', 'string'],
            'addresses' => ['sometimes', 'array'],
            'addresses.*.type' => ['required_with:addresses', 'in:main,billing,shipping,other'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:10'],
            'addresses.*.street' => ['nullable', 'string', 'max:255'],
            'addresses.*.number' => ['nullable', 'string', 'max:30'],
            'addresses.*.complement' => ['nullable', 'string', 'max:255'],
            'addresses.*.district' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'],
            'addresses.*.state' => ['nullable', 'string', 'size:2'],
            'contacts' => ['sometimes', 'array'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            'contacts.*.whatsapp' => ['nullable', 'string', 'max:30'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('document') || ! filled($this->input('document'))) {
                return;
            }

            $company = $this->attributes->get('company');
            $document = BrazilianDocument::normalize($this->input('document'));

            if ($company !== null && Party::query()->where('company_id', $company->id)->where('document', $document)->exists()) {
                $validator->errors()->add('document', 'Já existe um cliente com este documento nesta empresa.');
            }
        }];
    }
}
