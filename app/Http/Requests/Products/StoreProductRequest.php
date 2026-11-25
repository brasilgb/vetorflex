<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\Products\Concerns\ValidatesSellableUnit;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
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

        foreach (['ncm', 'cest'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => preg_replace('/[\s.\-]/', '', $this->input($field)) ?: null]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ProductType::class)],
            ...$this->productRules(null),
            ...$this->sellableUnitRules(null),
            'unit_of_measure_id' => ['nullable', 'integer', $this->activeRecord('units_of_measure', null)],
            'initial_stock' => [Rule::excludeIf(! $this->isAllowed(ProductPermission::AdjustInventory) || $this->input('type') !== ProductType::Simple->value), 'nullable', 'decimal:0,4', 'gt:0', 'max:99999999999'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [$this->validateBarcode(...)];
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    protected function productRules(?Product $product): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'internal_code' => ['nullable', 'string', 'max:60', Rule::unique('products', 'internal_code')->where('company_id', $this->company()->id)->ignore($product?->id)],
            'product_category_id' => ['nullable', 'integer', $this->activeRecord('product_categories', $product?->product_category_id)],
            'product_brand_id' => ['nullable', 'integer', $this->activeRecord('product_brands', $product?->product_brand_id)],
            'manufacturer_reference' => ['nullable', 'string', 'max:100'],
            'tracks_inventory' => ['sometimes', 'boolean'],
            'ncm' => ['nullable', 'digits:8'],
            'cest' => ['nullable', 'digits:7'],
            'merchandise_origin' => ['nullable', 'in:0,1,2,3,4,5,6,7,8'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Registro ativo da empresa. O vínculo atual é aceito mesmo que tenha sido inativado depois.
     */
    protected function activeRecord(string $table, ?int $currentId): Exists
    {
        return Rule::exists($table, 'id')->where(fn (Builder $query) => $query
            ->where('company_id', $this->company()->id)
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($currentId !== null, fn (Builder $query) => $query->orWhere('id', $currentId))));
    }

    protected function defaultVariant(Product $product): ?ProductVariant
    {
        return $product->variants()->where('is_default', true)->first();
    }
}
