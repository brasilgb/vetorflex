<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductImageRequest;
use App\Http\Requests\Products\UpdateProductImageRequest;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Services\ProductImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageController extends Controller
{
    public function __construct(private ProductImageService $images) {}

    public function show(Product $product, ProductImage $image): StreamedResponse
    {
        abort_unless(Storage::disk($image->disk)->exists($image->path), 404);

        return Storage::disk($image->disk)->response($image->path, basename($image->path), [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(StoreProductImageRequest $request, Product $product): RedirectResponse
    {
        $variantId = $request->validated('product_variant_id');
        $this->images->store($product, $request->user(), $request->file('image'), $variantId === null ? null : (int) $variantId);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Imagem adicionada.']);

        return to_route('products.show', $product);
    }

    public function update(UpdateProductImageRequest $request, Product $product, ProductImage $image): RedirectResponse
    {
        $data = $request->validated();

        if (array_key_exists('product_variant_id', $data)) {
            $image->update(['product_variant_id' => $data['product_variant_id']]);
        }

        if (isset($data['is_primary'])) {
            $this->images->makePrimary($image);
        }

        if (isset($data['direction'])) {
            $this->images->move($image, $data['direction'] === 'previous' ? -1 : 1);
        }

        return to_route('products.show', $product);
    }

    public function destroy(Product $product, ProductImage $image): RedirectResponse
    {
        $this->images->delete($image);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Imagem excluída.']);

        return to_route('products.show', $product);
    }
}
