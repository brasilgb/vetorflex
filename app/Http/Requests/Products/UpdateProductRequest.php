<?php

namespace App\Http\Requests\Products;

use App\Modules\Products\Models\Product;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        /** @var Product $product */
        $product = $this->route('product');

        return [
            ...$this->productRules($product),
            ...$this->sellableUnitRules($this->defaultVariant($product)),
            'unit_of_measure_id' => ['required', 'integer', $this->activeRecord('units_of_measure', $product->unit_of_measure_id)],
        ];
    }
}
