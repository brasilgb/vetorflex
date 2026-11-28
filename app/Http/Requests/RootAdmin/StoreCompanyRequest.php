<?php

namespace App\Http\Requests\RootAdmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_root_admin === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:companies,slug'],
            'timezone' => ['nullable', 'timezone'],
            'document' => ['nullable', 'string', 'regex:/^\D*(\d\D*){11}$|^\D*(\d\D*){14}$/'],
            'owner_name' => ['nullable', 'required_with:owner_email', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email', 'max:255'],
            'modules' => ['nullable', 'array', 'max:30'],
            'modules.*' => ['string', 'max:40'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'idempotency_key' => ['nullable', 'uuid'],
        ];
    }
}
