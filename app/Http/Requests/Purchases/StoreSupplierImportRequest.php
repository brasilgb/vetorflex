<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\Products\StoreProductImportRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreSupplierImportRequest extends StoreProductImportRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['file' => [
            'bail', 'required', 'file', 'extensions:csv,txt',
            'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
            'max:'.config('purchases.import.maximum_kilobytes'),
        ]];
    }
}
