<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\GenerateProductVariantsRequest;
use App\Http\Requests\Products\UpdateProductVariantRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductService;
use App\Modules\Products\Services\VariantGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class ProductVariantController extends Controller
{
    public function preview(GenerateProductVariantsRequest $request, Product $product, VariantGenerator $generator): JsonResponse
    {
        return response()->json($generator->preview($product, $request->validated('attributes')));
    }

    public function store(GenerateProductVariantsRequest $request, Product $product, VariantGenerator $generator): RedirectResponse
    {
        $data = $request->validated();
        $created = $generator->generate($product, $request->user(), $data['attributes'], $data['labels'] ?? [], Arr::except($data, ['attributes', 'labels']));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$created} variação(ões) criada(s)."]);

        return to_route('products.show', $product);
    }

    public function update(UpdateProductVariantRequest $request, Product $product, ProductVariant $variant, ProductService $products, AuditService $audit): RedirectResponse
    {
        abort_unless($product->type === ProductType::Variable, 404);

        $products->saveVariant($variant, $request->validated(), $request->user());
        $audit->record('product.variant_updated', $product->company, $request->user(), $variant);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Variação atualizada.']);

        return to_route('products.show', $product);
    }
}
