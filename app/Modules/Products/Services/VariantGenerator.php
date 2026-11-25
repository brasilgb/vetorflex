<?php

namespace App\Modules\Products\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductAttribute;
use App\Modules\Products\Models\ProductAttributeValue;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gera combinações de atributos. A prévia não grava nada; a confirmação ignora as já existentes.
 *
 * @phpstan-type AttributeInput array{name: string, values: list<string>}
 */
class VariantGenerator
{
    public const MAXIMUM_COMBINATIONS = 100;

    public function __construct(private ProductService $products, private AuditService $audit) {}

    /**
     * @param  list<AttributeInput>  $attributes
     * @return list<array{label: string, exists: bool}>
     */
    public function preview(Product $product, array $attributes): array
    {
        $combinations = $this->combinations($product, $attributes);
        $existing = $this->existingSignatures($product);

        return array_map(fn (array $combination): array => [
            'label' => implode(' / ', $combination),
            'exists' => isset($existing[$this->signature($combination)]),
        ], $combinations);
    }

    /**
     * @param  list<AttributeInput>  $attributes
     * @param  list<string>  $labels  Combinações confirmadas na prévia.
     * @param  array<string, mixed>  $defaults  Preços e opções aplicados às novas variações.
     */
    public function generate(Product $product, User $user, array $attributes, array $labels, array $defaults): int
    {
        return DB::transaction(function () use ($product, $user, $attributes, $labels, $defaults): int {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $existing = $this->existingSignatures($product);
            $created = 0;

            foreach ($this->combinations($product, $attributes) as $combination) {
                $label = implode(' / ', $combination);

                if (isset($existing[$this->signature($combination)]) || ! in_array($label, $labels, true)) {
                    continue;
                }

                $values = $this->resolveValues($product->company_id, $combination);
                $variant = $this->products->saveVariant(new ProductVariant([
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'label' => $label,
                    'combination_key' => $this->combinationKey($values),
                ]), $defaults, $user);
                $variant->attributeValues()->attach(Arr::mapWithKeys($values, fn (ProductAttributeValue $value): array => [
                    $value->id => ['company_id' => $product->company_id, 'product_attribute_id' => $value->product_attribute_id],
                ]));
                $created++;
            }

            if ($created === 0) {
                throw ValidationException::withMessages(['attributes' => 'Nenhuma combinação nova foi confirmada.']);
            }

            $this->audit->record('product.variants_generated', $product->company, $user, $product, ['created' => $created]);

            return $created;
        });
    }

    /**
     * @param  list<AttributeInput>  $attributes
     * @return list<array<string, string>>
     */
    private function combinations(Product $product, array $attributes): array
    {
        if ($product->type !== ProductType::Variable) {
            throw ValidationException::withMessages(['attributes' => 'Somente produtos com variações aceitam combinações.']);
        }

        $sets = [];

        foreach ($attributes as $attribute) {
            $name = Str::squish($attribute['name']);

            if (isset($sets[$this->fold($name)])) {
                throw ValidationException::withMessages(['attributes' => "O atributo {$name} foi informado mais de uma vez."]);
            }

            $values = [];

            foreach ($attribute['values'] as $value) {
                $value = Str::squish($value);
                $values[$this->fold($value)] ??= $value;
            }

            $sets[$this->fold($name)] = ['name' => $name, 'values' => array_values($values)];
        }

        $this->ensureSameAttributes($product, array_keys($sets));

        if (array_product(array_map(fn (array $set): int => count($set['values']), $sets)) > self::MAXIMUM_COMBINATIONS) {
            throw ValidationException::withMessages(['attributes' => 'Gere no máximo '.self::MAXIMUM_COMBINATIONS.' combinações por vez.']);
        }

        $combinations = [[]];

        foreach ($sets as $set) {
            $expanded = [];

            foreach ($combinations as $combination) {
                foreach ($set['values'] as $value) {
                    $expanded[] = [...$combination, $set['name'] => $value];
                }
            }

            $combinations = $expanded;
        }

        return $combinations;
    }

    /** @param list<string> $names */
    private function ensureSameAttributes(Product $product, array $names): void
    {
        $variant = $product->variants()->with('attributeValues.attribute')->first();

        if ($variant === null) {
            return;
        }

        $current = $variant->attributeValues->map(fn (ProductAttributeValue $value): string => $this->fold($value->attribute->name))->sort()->values()->all();

        if ($current !== collect($names)->sort()->values()->all()) {
            throw ValidationException::withMessages(['attributes' => 'Use os mesmos atributos das variações já cadastradas neste produto.']);
        }
    }

    /** @return array<string, true> */
    private function existingSignatures(Product $product): array
    {
        $signatures = [];

        foreach ($product->variants()->with('attributeValues.attribute')->get() as $variant) {
            $combination = [];

            foreach ($variant->attributeValues as $value) {
                $combination[$value->attribute->name] = $value->value;
            }

            $signatures[$this->signature($combination)] = true;
        }

        return $signatures;
    }

    /** @param array<string, string> $combination */
    private function signature(array $combination): string
    {
        $parts = [];

        foreach ($combination as $name => $value) {
            $parts[$this->fold($name)] = $this->fold($value);
        }

        ksort($parts);

        return (string) json_encode($parts);
    }

    /**
     * @param  array<string, string>  $combination
     * @return list<ProductAttributeValue>
     */
    private function resolveValues(int $companyId, array $combination): array
    {
        $values = [];

        foreach ($combination as $name => $value) {
            $attribute = ProductAttribute::query()->where('company_id', $companyId)->get()->first(fn (ProductAttribute $attribute): bool => $this->fold($attribute->name) === $this->fold($name))
                ?? ProductAttribute::create(['company_id' => $companyId, 'name' => $name]);
            $values[] = $attribute->values()->get()->first(fn (ProductAttributeValue $existing): bool => $this->fold($existing->value) === $this->fold($value))
                ?? $attribute->values()->create(['company_id' => $companyId, 'value' => $value]);
        }

        return $values;
    }

    /** @param list<ProductAttributeValue> $values */
    private function combinationKey(array $values): string
    {
        return collect($values)->map(fn (ProductAttributeValue $value): string => "{$value->product_attribute_id}:{$value->id}")->sort()->implode('|');
    }

    /**
     * Equivale à comparação do banco (sem diferenciar maiúsculas e acentos).
     */
    private function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
