<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Enums\BarcodeType;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\UnitOfMeasure;
use App\Modules\Products\Support\Gtin;
use App\Modules\Products\Support\ProductCsv;
use App\Modules\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Classifica as linhas lidas. Não grava nada; é executado na prévia e novamente na confirmação.
 *
 * @phpstan-type RawRow array{line: int, data: array<string, string>, errors: list<string>}
 * @phpstan-type EvaluatedRow array{line: int, name: string, status: 'new'|'duplicate'|'invalid', attributes: array<string, mixed>, category: list<string>, brand: string|null, product_id: int|null, product_name: string|null, errors: list<string>, warnings: list<string>}
 */
class ProductImportEvaluator
{
    private const TEXT_COLUMNS = [
        'nome' => 'name', 'sku' => 'sku', 'codigo_interno' => 'internal_code', 'codigo_barras' => 'barcode',
        'referencia_fabricante' => 'manufacturer_reference', 'descricao_curta' => 'short_description', 'descricao' => 'description',
        'ncm' => 'ncm', 'cest' => 'cest', 'origem' => 'merchandise_origin', 'observacoes' => 'notes',
    ];

    private const DECIMAL_COLUMNS = [
        'preco_custo' => 'cost_price', 'preco_venda' => 'sale_price', 'preco_promocional' => 'promotional_price',
        'estoque_minimo' => 'minimum_stock', 'estoque_inicial' => 'initial_stock',
    ];

    /** @var Collection<int, ProductVariant> */
    private Collection $variants;

    /** @var array<string, UnitOfMeasure> */
    private array $units;

    /** @var array<string, bool> */
    private array $brands;

    /** @var Collection<int, ProductCategory> */
    private Collection $categories;

    /**
     * @param  list<RawRow>  $rows
     * @param  Closure(ProductPermission): bool  $allows
     * @return list<EvaluatedRow>
     */
    public function evaluate(Company $company, array $rows, Closure $allows): array
    {
        $parsed = array_map(fn (array $row): array => [...$row, 'attributes' => $this->attributes($row['data'], $allows)], $rows);
        $this->load($company, array_column($parsed, 'attributes'));
        $seen = [];
        $evaluated = [];

        foreach ($parsed as $row) {
            $evaluated[] = $this->evaluateRow($row, $allows, $seen);
        }

        return $evaluated;
    }

    /**
     * @param  array{line: int, data: array<string, string>, errors: list<string>, attributes: array<string, mixed>}  $row
     * @param  Closure(ProductPermission): bool  $allows
     * @param  array<string, int>  $seen
     * @return EvaluatedRow
     */
    private function evaluateRow(array $row, Closure $allows, array &$seen): array
    {
        $attributes = $row['attributes'];
        $errors = [...$row['errors'], ...$this->unsupported($row['data']), ...$this->validate($attributes)];
        $warnings = [];

        foreach ($this->identifiers($attributes) as $key) {
            if (isset($seen[$key])) {
                $errors[] = "Registro repetido no arquivo (linha {$seen[$key]}).";

                break;
            }
        }

        foreach ($this->identifiers($attributes) as $key) {
            $seen[$key] ??= $row['line'];
        }

        $matches = $this->matches($attributes);
        $match = $matches->first();
        $product = $match?->product;

        if ($matches->pluck('product_id')->unique()->count() > 1) {
            $errors[] = 'O registro corresponde a mais de um produto existente.';
        } elseif ($product !== null && $product->type !== ProductType::Simple) {
            $errors[] = "O código já pertence a \"{$product->name}\", que não é um produto simples.";
        } elseif ($product !== null && ! $allows(ProductPermission::Update)) {
            $warnings[] = 'Sem permissão para atualizar produtos existentes.';
        } elseif ($product === null && ! $allows(ProductPermission::Create)) {
            $errors[] = 'Sem permissão para cadastrar produtos.';
        }

        $hasSameName = $match === null && $this->variants->contains(fn (ProductVariant $variant): bool => $this->fold($variant->product->name) === $this->fold((string) ($attributes['name'] ?? '')));

        if ($hasSameName) {
            $warnings[] = 'Já existe um produto com este nome e outros códigos.';
        }

        if ($match !== null) {
            $errors = [...$errors, ...$this->promotionalErrors($attributes, $match)];

            if (isset($attributes['initial_stock'])) {
                unset($attributes['initial_stock']);
                $warnings[] = 'O estoque inicial é ignorado em produtos existentes.';
            }
        }

        $errors = [...$errors, ...$this->resolveUnit($row['data']['unidade'] ?? '', $attributes, $product)];
        $category = $this->categoryPath($row['data']['categoria'] ?? '');
        $brand = Str::squish($row['data']['marca'] ?? '');

        foreach ([
            $this->categoryNotice($category, $allows(ProductPermission::ManageCategories)),
            $this->brandNotice($brand, $allows(ProductPermission::ManageBrands)),
        ] as [$error, $warning]) {
            $errors = [...$errors, ...array_filter([$error])];
            $warnings = [...$warnings, ...array_filter([$warning])];
        }

        return [
            'line' => $row['line'],
            'name' => (string) ($attributes['name'] ?? ''),
            'status' => $errors !== [] ? 'invalid' : ($product === null ? 'new' : 'duplicate'),
            'attributes' => $attributes,
            'category' => $category,
            'brand' => $brand === '' ? null : $brand,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
        ];
    }

    /**
     * Células vazias não geram atributo, para que uma atualização não apague dados existentes.
     *
     * @param  array<string, string>  $data
     * @param  Closure(ProductPermission): bool  $allows
     * @return array<string, mixed>
     */
    private function attributes(array $data, Closure $allows): array
    {
        $attributes = [];

        foreach (self::TEXT_COLUMNS as $column => $attribute) {
            if (($data[$column] ?? '') !== '') {
                $attributes[$attribute] = $data[$column];
            }
        }

        foreach (self::DECIMAL_COLUMNS as $column => $attribute) {
            if (($data[$column] ?? '') !== '') {
                $attributes[$attribute] = ProductCsv::parseDecimal($data[$column]);
            }
        }

        foreach (['sku', 'barcode'] as $attribute) {
            if (isset($attributes[$attribute])) {
                $attributes[$attribute] = Str::upper($attributes[$attribute]);
            }
        }

        foreach (['ncm', 'cest'] as $attribute) {
            if (isset($attributes[$attribute])) {
                $attributes[$attribute] = preg_replace('/[\s.\-]/', '', $attributes[$attribute]);
            }
        }

        if (isset($attributes['barcode'])) {
            $attributes = [...$attributes, ...$this->barcode($attributes['barcode'], $this->fold($data['tipo_codigo'] ?? ''))];
        }

        if (($data['controla_estoque'] ?? '') !== '') {
            $attributes['tracks_inventory'] = match ($this->fold($data['controla_estoque'])) {
                'sim', 's', '1' => true,
                'nao', 'n', '0' => false,
                default => $data['controla_estoque'],
            };
        }

        $forbidden = [
            ...($allows(ProductPermission::ViewCost) && $allows(ProductPermission::ManageCost) ? [] : ['cost_price']),
            ...($allows(ProductPermission::ManagePrices) ? [] : ['sale_price', 'promotional_price']),
            ...($allows(ProductPermission::AdjustInventory) ? [] : ['initial_stock']),
        ];

        return array_diff_key($attributes, array_flip($forbidden));
    }

    /** @return array{barcode: string, barcode_type: string} */
    private function barcode(string $barcode, string $type): array
    {
        $gtin = Gtin::normalize($barcode);
        $barcodeType = match ($type) {
            'gtin', 'ean', 'upc', 'gtin/ean/upc' => BarcodeType::Gtin->value,
            'interno', 'codigo interno' => BarcodeType::Internal->value,
            '' => Gtin::isValid($gtin) ? BarcodeType::Gtin->value : BarcodeType::Internal->value,
            default => $type,
        };

        return ['barcode' => $barcodeType === BarcodeType::Gtin->value ? $gtin : $barcode, 'barcode_type' => $barcodeType];
    }

    /**
     * @param  array<string, string>  $data
     * @return list<string>
     */
    private function unsupported(array $data): array
    {
        $type = $this->fold($data['tipo'] ?? '');
        $isSimple = in_array($type, ['', 'simples', $this->fold(ProductType::Simple->label())], true);

        return ! $isSimple || ($data['variacao'] ?? '') !== '' ? ['Somente produtos simples podem ser importados.'] : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function validate(array $attributes): array
    {
        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:60'],
            'internal_code' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'barcode_type' => ['nullable', 'in:gtin,internal'],
            'manufacturer_reference' => ['nullable', 'string', 'max:100'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'cost_price' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'sale_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'promotional_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'minimum_stock' => ['nullable', 'decimal:0,4', 'min:0', 'max:99999999999'],
            'initial_stock' => ['nullable', 'decimal:0,4', 'gt:0', 'max:99999999999'],
            'tracks_inventory' => ['nullable', 'boolean:strict'],
            'ncm' => ['nullable', 'digits:8'],
            'cest' => ['nullable', 'digits:7'],
            'merchandise_origin' => ['nullable', 'in:0,1,2,3,4,5,6,7,8'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'required' => 'Informe :attribute.',
            'max' => 'O campo :attribute excede o limite.',
            'decimal' => 'O campo :attribute não é um número válido.',
            'min' => 'O campo :attribute não pode ser negativo.',
            'gt' => 'O campo :attribute deve ser maior que zero.',
            'digits' => 'O campo :attribute deve ter :digits dígitos.',
            'in' => 'O campo :attribute tem um valor desconhecido.',
            'boolean' => 'O campo :attribute deve ser "sim" ou "nao".',
        ], [...array_flip(self::TEXT_COLUMNS), ...array_flip(self::DECIMAL_COLUMNS), 'barcode_type' => 'tipo_codigo', 'tracks_inventory' => 'controla_estoque']);

        $errors = array_values($validator->errors()->all());

        if (($attributes['barcode_type'] ?? null) === BarcodeType::Gtin->value && ! Gtin::isValid($attributes['barcode'])) {
            $errors[] = 'Código GTIN/EAN/UPC inválido.';
        }

        if ($errors === [] && isset($attributes['initial_stock']) && ($attributes['tracks_inventory'] ?? true) === false) {
            $errors[] = 'Estoque inicial informado para produto sem controle de estoque.';
        }

        if ($errors === [] && isset($attributes['promotional_price'], $attributes['sale_price']) && BigDecimal::of($attributes['promotional_price'])->isGreaterThan($attributes['sale_price'])) {
            $errors[] = 'O preço promocional não pode ser maior que o preço de venda.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function promotionalErrors(array $attributes, ProductVariant $variant): array
    {
        $promotional = $attributes['promotional_price'] ?? $variant->promotional_price;
        $sale = $attributes['sale_price'] ?? $variant->sale_price;
        $isChanged = isset($attributes['promotional_price']) || isset($attributes['sale_price']);

        if (! $isChanged || $promotional === null || ! is_numeric($promotional) || ! is_numeric($sale)) {
            return $isChanged && isset($attributes['promotional_price']) && $sale === null ? ['Informe o preço de venda para usar preço promocional.'] : [];
        }

        return BigDecimal::of((string) $promotional)->isGreaterThan((string) $sale) ? ['O preço promocional não pode ser maior que o preço de venda.'] : [];
    }

    /**
     * Sem nenhum código, o nome identifica o registro.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function identifiers(array $attributes): array
    {
        $keys = [];

        foreach (['sku', 'barcode', 'internal_code'] as $field) {
            if (isset($attributes[$field])) {
                $keys[] = "{$field}:".$this->fold($attributes[$field]);
            }
        }

        return $keys === [] && isset($attributes['name']) ? ['name:'.$this->fold($attributes['name'])] : $keys;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, ProductVariant>
     */
    private function matches(array $attributes): Collection
    {
        $identifiers = $this->identifiers($attributes);

        return $this->variants->filter(fn (ProductVariant $variant): bool => array_intersect($identifiers, [
            'sku:'.$this->fold((string) $variant->sku),
            'barcode:'.$this->fold((string) $variant->barcode),
            'internal_code:'.$this->fold((string) $variant->product->internal_code),
            'name:'.$this->fold($variant->product->name),
        ]) !== [])->values();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function resolveUnit(string $code, array &$attributes, ?Product $product): array
    {
        $unit = $code === '' ? ($product === null ? $this->units['un'] ?? null : $product->unit) : $this->units[$this->fold($code)] ?? null;

        if ($code !== '') {
            if ($unit === null || ! $unit->is_active) {
                return ["Unidade de medida não encontrada: {$code}."];
            }

            $attributes['unit_of_measure_id'] = $unit->id;
        }

        $stock = $attributes['initial_stock'] ?? null;

        return is_numeric($stock) && $unit !== null && ! $unit->allows_fraction && BigDecimal::of((string) $stock)->strippedOfTrailingZeros()->getScale() > 0
            ? ["A unidade {$unit->code} não aceita quantidade fracionária."]
            : [];
    }

    /** @return list<string> */
    private function categoryPath(string $value): array
    {
        return array_values(array_filter(array_map(Str::squish(...), preg_split('/[>›]/u', $value) ?: []), fn (string $name): bool => $name !== ''));
    }

    /**
     * @param  list<string>  $path
     * @return array{string|null, string|null}
     */
    private function categoryNotice(array $path, bool $canCreate): array
    {
        $parentId = null;

        foreach ($path as $name) {
            if (mb_strlen($name) > 120) {
                return ['O nome da categoria excede 120 caracteres.', null];
            }

            $category = $this->categories->first(fn (ProductCategory $category): bool => $category->parent_id === $parentId && $this->fold($category->name) === $this->fold($name));

            if ($category === null) {
                $label = implode(' > ', $path);

                return $canCreate ? [null, "A categoria \"{$label}\" será criada."] : ["Categoria não encontrada: {$label}.", null];
            }

            if (! $category->is_active) {
                return ["Categoria inativa: {$category->name}.", null];
            }

            $parentId = $category->id;
        }

        return [null, null];
    }

    /** @return array{string|null, string|null} */
    private function brandNotice(string $name, bool $canCreate): array
    {
        if ($name === '') {
            return [null, null];
        }

        if (mb_strlen($name) > 120) {
            return ['O nome da marca excede 120 caracteres.', null];
        }

        return match ($this->brands[$this->fold($name)] ?? null) {
            true => [null, null],
            false => ["Marca inativa: {$name}.", null],
            null => $canCreate ? [null, "A marca \"{$name}\" será criada."] : ["Marca não encontrada: {$name}.", null],
        };
    }

    /** @param list<array<string, mixed>> $attributes */
    private function load(Company $company, array $attributes): void
    {
        $values = fn (string $field): array => array_values(array_unique(array_filter(array_column($attributes, $field))));

        $this->variants = ProductVariant::query()
            ->where('product_variants.company_id', $company->id)
            ->with('product.unit')
            ->where(fn (Builder $query) => $query
                ->whereIn('sku', $values('sku'))
                ->orWhereIn('barcode', $values('barcode'))
                ->orWhereHas('product', fn (Builder $product) => $product
                    ->whereIn('internal_code', $values('internal_code'))
                    ->orWhereIn('name', $values('name'))))
            ->orderBy('id')
            ->get();

        $this->units = UnitOfMeasure::query()->where('company_id', $company->id)->get()->keyBy(fn (UnitOfMeasure $unit): string => $this->fold($unit->code))->all();
        $this->brands = ProductBrand::query()->where('company_id', $company->id)->get()->mapWithKeys(fn (ProductBrand $brand): array => [$this->fold($brand->name) => $brand->is_active])->all();
        $this->categories = ProductCategory::query()->where('company_id', $company->id)->get();
    }

    /**
     * Equivale à comparação do banco (sem diferenciar maiúsculas e acentos).
     */
    private function fold(string $value): string
    {
        return Str::lower(Str::ascii(Str::squish($value)));
    }
}
