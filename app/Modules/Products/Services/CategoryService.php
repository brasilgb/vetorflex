<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\ProductCategory;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    /** @param array<string, mixed> $data */
    public function save(ProductCategory $category, array $data): ProductCategory
    {
        return DB::transaction(function () use ($category, $data): ProductCategory {
            $parents = ProductCategory::query()->where('company_id', $category->company_id)->lockForUpdate()->pluck('parent_id', 'id')->all();
            $category->fill($data);
            $this->ensureAcyclic($category, $parents);

            $isDuplicated = ProductCategory::query()
                ->where('company_id', $category->company_id)
                ->where('parent_id', $category->parent_id)
                ->where('name', $category->name)
                ->when($category->exists, fn ($query) => $query->whereKeyNot($category->id))
                ->exists();

            if ($isDuplicated) {
                throw ValidationException::withMessages(['name' => 'Já existe uma categoria com este nome neste nível.']);
            }

            $category->save();

            return $category;
        });
    }

    /**
     * Categorias ativas em ordem hierárquica. Uma categoria já vinculada é mantida mesmo se inativa.
     *
     * @return list<array{id: int, name: string}>
     */
    public function options(Company $company, ?int $currentId = null): array
    {
        $paths = $this->paths($company);
        $options = ProductCategory::query()->where('company_id', $company->id)
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $currentId))
            ->get()
            ->map(fn (ProductCategory $category): array => ['id' => $category->id, 'name' => $paths[$category->id]])
            ->all();

        usort($options, fn (array $first, array $second): int => strnatcasecmp($first['name'], $second['name']));

        return $options;
    }

    /** @return array<int, string> Caminho completo de cada categoria, pelo identificador. */
    public function paths(Company $company): array
    {
        $categories = ProductCategory::query()->where('company_id', $company->id)->get()->keyBy('id');
        $paths = [];

        foreach ($categories as $category) {
            $names = [$category->name];
            $parentId = $category->parent_id;

            while ($parentId !== null && ($parent = $categories->get($parentId)) !== null && count($names) <= $categories->count()) {
                array_unshift($names, $parent->name);
                $parentId = $parent->parent_id;
            }

            $paths[$category->id] = implode(' › ', $names);
        }

        return $paths;
    }

    /** @return list<int> A categoria e todas as suas subcategorias. */
    public function descendantIds(Company $company, int $categoryId): array
    {
        $parents = ProductCategory::query()->where('company_id', $company->id)->pluck('parent_id', 'id')->all();
        $ids = [$categoryId];

        for ($index = 0; $index < count($ids); $index++) {
            foreach (array_keys($parents, $ids[$index], true) as $childId) {
                if (! in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                }
            }
        }

        return $ids;
    }

    /** @param array<int, int|null> $parents */
    private function ensureAcyclic(ProductCategory $category, array $parents): void
    {
        $ancestorId = $category->parent_id;
        $visited = [];

        while ($ancestorId !== null && ! isset($visited[$ancestorId])) {
            if ($category->exists && $ancestorId === $category->id) {
                throw ValidationException::withMessages(['parent_id' => 'Uma categoria não pode ser subordinada a si mesma ou às suas subcategorias.']);
            }

            $visited[$ancestorId] = true;
            $ancestorId = $parents[$ancestorId] ?? null;
        }
    }
}
