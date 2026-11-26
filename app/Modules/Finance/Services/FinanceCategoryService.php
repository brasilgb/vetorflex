<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\CategoryKind;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Categorias gerenciais. As sugeridas são criadas no primeiro uso e podem ser editadas ou inativadas.
 */
class FinanceCategoryService
{
    /** @var array<string, list<string>> */
    private const DEFAULTS = [
        'income' => ['Venda de mercadorias', 'Prestação de serviços', 'Outras receitas'],
        'expense' => ['Mercadorias', 'Fornecedores', 'Aluguel', 'Energia', 'Internet', 'Impostos', 'Marketing', 'Salários', 'Transporte', 'Outras despesas'],
    ];

    public function ensureDefaults(Company $company): void
    {
        if (FinanceCategory::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $kind => $names) {
            FinanceCategory::query()->insertOrIgnore(array_map(fn (string $name): array => [
                'company_id' => $company->id,
                'kind' => $kind,
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], $names));
        }
    }

    /** Categoria padrão para títulos gerados por integrações, criada sob demanda. */
    public function defaultFor(Company $company, CategoryKind $kind, string $name): FinanceCategory
    {
        $this->ensureDefaults($company);

        return FinanceCategory::query()->firstOrCreate(['company_id' => $company->id, 'kind' => $kind->value, 'name' => $name], ['is_active' => true]);
    }

    /** @return Collection<int, FinanceCategory> */
    public function forCompany(Company $company): Collection
    {
        $this->ensureDefaults($company);

        return FinanceCategory::query()->where('company_id', $company->id)->with('parent')->orderBy('kind')->orderBy('name')->get();
    }

    /** @param array{kind: string, name: string, parent_id?: int|null, is_active?: bool} $data */
    public function save(Company $company, FinanceCategory $category, array $data): FinanceCategory
    {
        $parent = isset($data['parent_id']) ? FinanceCategory::query()->where('company_id', $company->id)->find($data['parent_id']) : null;

        if (isset($data['parent_id']) && ($parent === null || $parent->parent_id !== null || $parent->kind->value !== $data['kind'] || $parent->id === $category->id)) {
            throw ValidationException::withMessages(['parent_id' => 'A categoria principal precisa ser do mesmo tipo e não pode ser uma subcategoria.']);
        }

        $category->fill(['company_id' => $company->id, 'kind' => $data['kind'], 'name' => trim($data['name']), 'parent_id' => $parent?->id, 'is_active' => $data['is_active'] ?? true])->save();

        return $category;
    }
}
