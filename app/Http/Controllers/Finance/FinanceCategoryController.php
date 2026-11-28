<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Enums\CategoryKind;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Services\FinanceCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações do Financeiro: categorias e subcategorias gerenciais. Categorias são inativadas, nunca excluídas.
 */
class FinanceCategoryController extends FinanceController
{
    public function __construct(private FinanceCategoryService $categoryService) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/settings', [
            'categories' => $this->categories($this->company($request), false),
            'kinds' => CategoryKind::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $category = $this->categoryService->save($this->company($request), new FinanceCategory, $this->validated($request, null));
        $this->toast("Categoria {$category->name} cadastrada.");

        return back();
    }

    public function update(Request $request, FinanceCategory $category): RedirectResponse
    {
        $this->categoryService->save($this->company($request), $category, $this->validated($request, $category));
        $this->toast("Categoria {$category->name} atualizada.");

        return back();
    }

    /** @return array{kind: string, name: string, parent_id?: int|null, is_active?: bool} */
    private function validated(Request $request, ?FinanceCategory $category): array
    {
        $companyId = $this->company($request)->id;

        return $request->validate([
            'kind' => ['required', Rule::enum(CategoryKind::class)],
            'name' => ['required', 'string', 'max:120', Rule::unique('finance_categories')->where('company_id', $companyId)->where('kind', $request->input('kind'))->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.required' => 'Informe o nome da categoria.', 'name.unique' => 'Já existe uma categoria com este nome.']);
    }
}
