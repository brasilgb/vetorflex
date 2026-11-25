<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\Products\Concerns\ValidatesSellableUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GenerateProductVariantsRequest extends FormRequest
{
    use ValidatesSellableUnit;

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
            'attributes' => ['required', 'array', 'list', 'max:5'],
            'attributes.*.name' => ['required', 'string', 'max:60'],
            'attributes.*.values' => ['required', 'array', 'list', 'max:50'],
            'attributes.*.values.*' => ['required', 'string', 'max:40'],
            'labels' => ['sometimes', 'array', 'list', 'max:100'],
            'labels.*' => ['string', 'max:255'],
            'generate_sku' => ['sometimes', 'boolean'],
            ...$this->priceRules(),
        ];
    }
}
