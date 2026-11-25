<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\SaveProductBrandRequest;
use App\Modules\Products\Models\ProductBrand;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductBrandController extends Controller
{
    use InteractsWithCompany;

    public function index(Request $request): Response
    {
        return Inertia::render('products/brands', [
            'records' => ProductBrand::query()->where('company_id', $this->company($request)->id)
                ->withCount('products')
                ->when($request->string('search')->trim()->value(), fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
                ->orderBy('name')->paginate(20)->withQueryString()
                ->through(fn (ProductBrand $brand): array => $brand->only(['id', 'name', 'is_active', 'products_count'])),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(SaveProductBrandRequest $request): RedirectResponse
    {
        ProductBrand::create([...$request->validated(), 'company_id' => $this->company($request)->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Marca cadastrada.']);

        return back();
    }

    public function update(SaveProductBrandRequest $request, ProductBrand $brand): RedirectResponse
    {
        $brand->update($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Marca atualizada.']);

        return back();
    }
}
