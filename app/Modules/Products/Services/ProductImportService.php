<?php

namespace App\Modules\Products\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Inventory\Data\StockOperation;
use App\Modules\Inventory\Enums\MovementOrigin;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Products\Enums\ProductPermission;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Support\ProductCsv;
use App\Modules\Tenancy\Models\Company;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Prévia e confirmação da importação. O estoque nunca é alterado por linha de produto existente.
 *
 * @phpstan-import-type EvaluatedRow from ProductImportEvaluator
 */
class ProductImportService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private ProductCsv $csv,
        private ProductImportEvaluator $evaluator,
        private ProductService $products,
        private CategoryService $categories,
        private UnitOfMeasureProvisioner $units,
        private InventoryService $inventory,
        private AuditService $audit,
    ) {}

    public function preview(Company $company, User $user, UploadedFile $file): ProductImport
    {
        $this->units->ensureDefaults($company);

        return ProductImport::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'file_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
            'status' => ProductImport::PREVIEW,
            'rows' => $this->csv->read($file),
        ]);
    }

    /**
     * @param  Closure(ProductPermission): bool  $allows
     * @return list<EvaluatedRow>
     */
    public function rows(ProductImport $import, Company $company, Closure $allows): array
    {
        return $this->evaluator->evaluate($company, $import->rows ?? [], $allows);
    }

    /**
     * @param  array<int, int>  $updates  Linhas confirmadas para atualização e o produto exibido na prévia.
     * @param  Closure(ProductPermission): bool  $allows
     */
    public function confirm(ProductImport $import, Company $company, User $user, array $updates, Closure $allows): ProductImport
    {
        return DB::transaction(function () use ($import, $company, $user, $updates, $allows): ProductImport {
            $import = ProductImport::query()->lockForUpdate()->findOrFail($import->id);

            if ($import->status === ProductImport::COMPLETED) {
                return $import;
            }

            $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

            foreach ($this->rows($import, $company, $allows) as $row) {
                [$outcome, $messages] = $this->processRow($import, $company, $user, $row, $updates[$row['line']] ?? null, $allows);
                $report[$outcome]++;

                if ($messages !== []) {
                    $report['errors'][] = ['line' => $row['line'], 'name' => $row['name'], 'messages' => $messages];
                }
            }

            $import->update(['status' => ProductImport::COMPLETED, 'rows' => null, 'report' => $report, 'completed_at' => now()]);
            $this->audit->record('product.imported', $company, $user, $import, Arr::except($report, 'errors'));

            return $import;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  EvaluatedRow  $row
     * @param  Closure(ProductPermission): bool  $allows
     * @return array{'created'|'updated'|'skipped'|'failed', list<string>}
     */
    private function processRow(ProductImport $import, Company $company, User $user, array $row, ?int $confirmedProductId, Closure $allows): array
    {
        if ($row['status'] === 'invalid') {
            return ['failed', $row['errors']];
        }

        if ($row['status'] === 'duplicate' && $confirmedProductId === null) {
            return ['skipped', ["Já existe: {$row['product_name']}. Atualização não confirmada."]];
        }

        if ($row['status'] === 'duplicate' && ($confirmedProductId !== $row['product_id'] || ! $allows(ProductPermission::Update))) {
            return ['skipped', ['O produto correspondente mudou desde a prévia ou a atualização não é permitida.']];
        }

        if ($row['status'] === 'new' && $confirmedProductId !== null) {
            return ['skipped', ['O produto a atualizar não corresponde mais a esta linha.']];
        }

        try {
            DB::transaction(fn () => $this->persist($import, $company, $user, $row));
        } catch (ValidationException $exception) {
            return ['failed', array_values(array_map(strval(...), Arr::flatten($exception->errors())))];
        } catch (UniqueConstraintViolationException) {
            return ['failed', ['Um dos códigos informados já está em uso nesta empresa.']];
        }

        return [$row['status'] === 'new' ? 'created' : 'updated', []];
    }

    /** @param EvaluatedRow $row */
    private function persist(ProductImport $import, Company $company, User $user, array $row): void
    {
        $data = Arr::except($row['attributes'], 'initial_stock');

        if ($row['category'] !== []) {
            $data['product_category_id'] = $this->categoryId($company, $row['category']);
        }

        if ($row['brand'] !== null) {
            $data['product_brand_id'] = $this->brandId($company, $row['brand']);
        }

        if ($row['status'] === 'duplicate') {
            $this->products->update(Product::query()->where('company_id', $company->id)->findOrFail($row['product_id']), $user, $data);

            return;
        }

        $product = $this->products->create($company, $user, [...$data, 'type' => ProductType::Simple->value]);

        if (isset($row['attributes']['initial_stock'])) {
            $this->inventory->recordInitial(new StockOperation(
                companyId: $company->id,
                variantId: $product->variants()->sole()->id,
                quantity: (string) $row['attributes']['initial_stock'],
                origin: MovementOrigin::Product,
                user: $user,
                reason: 'Estoque inicial informado na importação.',
                referenceType: 'product_import',
                referenceId: (string) $import->id,
                idempotencyKey: "product-import:{$import->id}:{$row['line']}",
            ));
        }
    }

    private function brandId(Company $company, string $name): int
    {
        $brand = ProductBrand::query()->where('company_id', $company->id)->get()
            ->first(fn (ProductBrand $brand): bool => $this->isSameName($brand->name, $name))
            ?? ProductBrand::create(['company_id' => $company->id, 'name' => $name]);

        return $brand->id;
    }

    private function isSameName(string $first, string $second): bool
    {
        return Str::lower(Str::ascii($first)) === Str::lower(Str::ascii($second));
    }

    /** @param list<string> $path */
    private function categoryId(Company $company, array $path): ?int
    {
        $parentId = null;

        foreach ($path as $name) {
            $category = ProductCategory::query()->where('company_id', $company->id)->where('parent_id', $parentId)->get()
                ->first(fn (ProductCategory $category): bool => $this->isSameName($category->name, $name))
                ?? $this->categories->save(new ProductCategory(['company_id' => $company->id]), ['name' => $name, 'parent_id' => $parentId]);
            $parentId = $category->id;
        }

        return $parentId;
    }
}
