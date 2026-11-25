<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SaveProductCategoryRequest;
use App\Modules\Products\Models\ProductCategory;
use App\Modules\Products\Services\CategoryService;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    use InteractsWithCompany;

    public function __construct(private CategoryService $categories) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        return Inertia::render('products/categories', [
            'records' => ProductCategory::query()->where('company_id', $company->id)
                ->with('parent')->withCount('products')
                ->when($request->string('search')->trim()->value(), fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
                ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString()
                ->through(fn (ProductCategory $category): array => [
                    ...$category->only(['id', 'name', 'parent_id', 'is_active', 'products_count']),
                    'parent' => $category->parent?->name,
                ]),
            'parents' => $this->categories->options($company),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(SaveProductCategoryRequest $request): RedirectResponse
    {
        return $this->save($request, new ProductCategory(['company_id' => $this->company($request)->id]), 'Categoria cadastrada.');
    }

    public function update(SaveProductCategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        return $this->save($request, $category, 'Categoria atualizada.');
    }

    private function save(SaveProductCategoryRequest $request, ProductCategory $category, string $message): RedirectResponse
    {
        $this->categories->save($category, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
