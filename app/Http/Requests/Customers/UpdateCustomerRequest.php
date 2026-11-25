<?php

namespace App\Http\Requests\Customers;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\BrazilianDocument;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('document') || ! filled($this->input('document'))) {
                return;
            }

            $company = $this->attributes->get('company');
            $customer = $this->route('customer');
            $document = BrazilianDocument::normalize($this->input('document'));

            if ($company !== null && Party::query()->where('company_id', $company->id)->where('document', $document)->whereKeyNot($customer)->exists()) {
                $validator->errors()->add('document', 'Já existe um cliente com este documento nesta empresa.');
            }
        }];
    }
}
