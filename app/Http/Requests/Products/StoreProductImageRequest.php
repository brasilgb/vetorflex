<?php

namespace App\Http\Requests\Products;

use App\Modules\Products\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductImageRequest extends FormRequest
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
        $dimension = (int) config('products.images.maximum_source_dimension');

        return [
            'image' => [
                'bail', 'required', 'file',
                'extensions:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.config('products.images.maximum_kilobytes'),
                Rule::dimensions()->maxWidth($dimension)->maxHeight($dimension),
            ],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)->where('company_id', $product->company_id)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required' => 'Selecione uma imagem.',
            'image.extensions' => 'Envie uma imagem JPG, PNG ou WebP.',
            'image.mimetypes' => 'Envie uma imagem JPG, PNG ou WebP.',
            'image.max' => 'A imagem excede o tamanho máximo permitido.',
            'image.uploaded' => 'A imagem excede o tamanho máximo permitido.',
            'image.dimensions' => 'A imagem excede as dimensões máximas permitidas.',
            'product_variant_id.exists' => 'A variação não pertence a este produto.',
        ];
    }
}
