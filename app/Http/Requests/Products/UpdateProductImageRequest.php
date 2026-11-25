<?php

namespace App\Http\Requests\Products;

use App\Modules\Products\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductImageRequest extends FormRequest
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
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'is_primary' => ['sometimes', 'accepted'],
            'direction' => ['sometimes', 'in:previous,next'],
            'product_variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)->where('company_id', $product->company_id)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['product_variant_id.exists' => 'A variação não pertence a este produto.'];
    }
}
