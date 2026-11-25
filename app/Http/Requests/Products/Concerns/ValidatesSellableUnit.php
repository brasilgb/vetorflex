<?php

namespace App\Http\Requests\Products\Concerns;

use App\Modules\Products\Enums\BarcodeType;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Support\Gtin;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Regras da unidade comercializável, comuns ao produto simples, ao serviço e às variações.
 */
trait ValidatesSellableUnit
{
    protected function company(): Company
    {
        return $this->attributes->get('company');
    }

    protected function isAllowed(ProductPermission $permission): bool
    {
        return app(TenantContext::class)->allows($this, $permission->value);
    }

    protected function normalizeSellableUnit(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => Str::upper(trim($this->input('sku'))) ?: null]);
        }

        if (is_string($this->input('barcode'))) {
            $barcode = Str::upper(trim($this->input('barcode')));
            $this->merge(['barcode' => ($this->input('barcode_type') === BarcodeType::Gtin->value ? Gtin::normalize($barcode) : $barcode) ?: null]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    protected function sellableUnitRules(?ProductVariant $variant): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('product_variants', 'sku')->where('company_id', $this->company()->id)->ignore($variant?->id)],
            'generate_sku' => ['sometimes', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:60', Rule::unique('product_variants', 'barcode')->where('company_id', $this->company()->id)->ignore($variant?->id)],
            'barcode_type' => ['nullable', 'required_with:barcode', Rule::enum(BarcodeType::class)],
            ...$this->priceRules(),
            'weight' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999'],
            'height' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999'],
            'width' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999'],
            'length' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999'],
        ];
    }

    /**
     * Campos de preço e custo sem permissão são descartados, preservando o valor gravado.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function priceRules(): array
    {
        $ignoresCost = ! $this->isAllowed(ProductPermission::ViewCost) || ! $this->isAllowed(ProductPermission::ManageCost);
        $ignoresPrices = ! $this->isAllowed(ProductPermission::ManagePrices);

        return [
            'cost_price' => [Rule::excludeIf($ignoresCost), 'nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'sale_price' => [Rule::excludeIf($ignoresPrices), 'nullable', 'required_with:promotional_price', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'promotional_price' => [Rule::excludeIf($ignoresPrices), 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999999', 'lte:sale_price'],
            'minimum_stock' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
        ];
    }

    protected function validateBarcode(Validator $validator): void
    {
        $barcode = $this->input('barcode');

        if ($validator->errors()->hasAny(['barcode', 'barcode_type']) || ! filled($barcode) || $this->input('barcode_type') !== BarcodeType::Gtin->value) {
            return;
        }

        if (! Gtin::isValid($barcode)) {
            $validator->errors()->add('barcode', 'Código GTIN/EAN/UPC inválido. Para códigos próprios, selecione "Código interno".');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Preencha este campo.',
            'required_with' => 'Preencha este campo.',
            'unique' => 'Este valor já está em uso nesta empresa.',
            'exists' => 'Selecione um registro ativo desta empresa.',
            'decimal' => 'Informe um número com a quantidade permitida de casas decimais.',
            'min' => 'O valor não pode ser negativo.',
            'max' => 'O valor excede o limite permitido.',
            'digits' => 'Informe exatamente :digits dígitos.',
            'promotional_price.lte' => 'O preço promocional não pode ser maior que o preço de venda.',
        ];
    }
}
