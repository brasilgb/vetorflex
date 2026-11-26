<?php

namespace App\Http\Requests\Purchases;

use App\Modules\Parties\Concerns\ValidatesParty;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Purchases\Models\SupplierProfile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Somente tipo e nome são obrigatórios, permitindo o cadastro simplificado.
 */
class SaveSupplierRequest extends FormRequest
{
    use ValidatesParty;

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
        $supplier = $this->supplier();

        return [
            ...$this->partyRules($supplier?->party),
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_code' => ['nullable', 'string', 'max:60', Rule::unique('supplier_profiles', 'internal_code')->where('company_id', $company->id)->ignore($supplier?->id)],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:999'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'minimum_order' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'link_existing' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome ou a razão social.',
            'internal_code.unique' => 'Este código interno já pertence a outro fornecedor.',
        ];
    }

    /**
     * O documento repetido no cadastro é tratado pelo serviço, que oferece o vínculo com a pessoa existente.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $supplier = $this->supplier();
            $document = BrazilianDocument::normalize($this->input('document'));

            if ($supplier === null || $document === null || $validator->errors()->has('document')) {
                return;
            }

            if (Party::query()->where('company_id', $supplier->company_id)->where('document', $document)->whereKeyNot($supplier->party_id)->exists()) {
                $validator->errors()->add('document', 'Já existe outra pessoa com este documento nesta empresa.');
            }
        }];
    }

    private function supplier(): ?SupplierProfile
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof SupplierProfile ? $supplier : null;
    }
}
