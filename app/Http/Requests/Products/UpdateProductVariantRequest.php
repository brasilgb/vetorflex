<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\Products\Concerns\ValidatesSellableUnit;
use App\Modules\Products\Models\ProductVariant;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductVariantRequest extends FormRequest
{
    use ValidatesSellableUnit;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeSellableUnit();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var ProductVariant $variant */
        $variant = $this->route('variant');

        return [...$this->sellableUnitRules($variant), 'is_active' => ['sometimes', 'boolean']];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [$this->validateBarcode(...)];
    }
}
