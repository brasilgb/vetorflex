<?php

namespace App\Http\Requests\Products;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductImportRequest extends FormRequest
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
        return ['file' => [
            'bail', 'required', 'file', 'extensions:csv,txt',
            'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
            'max:'.config('products.import.maximum_kilobytes'),
        ]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Selecione um arquivo CSV.',
            'file.extensions' => 'Envie um arquivo CSV.',
            'file.mimetypes' => 'Envie um arquivo CSV.',
            'file.max' => 'O arquivo excede o tamanho máximo permitido.',
            'file.uploaded' => 'O arquivo excede o tamanho máximo permitido.',
        ];
    }
}
