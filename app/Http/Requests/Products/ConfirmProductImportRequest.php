<?php

namespace App\Http\Requests\Products;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmProductImportRequest extends FormRequest
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
            'updates' => ['sometimes', 'array', 'list', 'max:'.config('products.import.maximum_rows')],
            'updates.*.line' => ['required', 'integer', 'distinct'],
            'updates.*.product_id' => ['required', 'integer'],
        ];
    }

    /** @return array<int, int> */
    public function updates(): array
    {
        return array_map(intval(...), array_column($this->validated('updates') ?? [], 'product_id', 'line'));
    }
}
