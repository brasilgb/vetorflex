<?php

namespace App\Modules\Products\Services;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Support\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductImageService
{
    public function __construct(private ImageOptimizer $optimizer) {}

    public function store(Product $product, User $user, UploadedFile $file, ?int $variantId): ProductImage
    {
        $image = $this->optimizer->optimize($file);
        $disk = (string) config('products.images.disk');
        $path = "companies/{$product->company_id}/products/{$product->id}/".Str::uuid().".{$image['extension']}";

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Não foi possível armazenar a imagem do produto.');
        }

        try {
            return DB::transaction(function () use ($product, $user, $file, $variantId, $image, $disk, $path): ProductImage {
                Product::query()->lockForUpdate()->findOrFail($product->id);

                if ($product->images()->count() >= (int) config('products.images.maximum_per_product')) {
                    throw ValidationException::withMessages(['image' => 'O limite de imagens deste produto foi atingido.']);
                }

                return $product->images()->create([
                    'company_id' => $product->company_id,
                    'product_variant_id' => $variantId,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
                    'mime_type' => $image['mime_type'],
                    'size' => strlen($image['contents']),
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'position' => ((int) $product->images()->max('position')) + 1,
                    'is_primary' => ! $product->images()->where('is_primary', true)->exists(),
                    'uploaded_by' => $user->id,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function makePrimary(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            Product::query()->lockForUpdate()->findOrFail($image->product_id);
            ProductImage::query()->where('product_id', $image->product_id)->update(['is_primary' => false]);
            ProductImage::query()->whereKey($image->id)->update(['is_primary' => true]);
        });
    }

    public function move(ProductImage $image, int $offset): void
    {
        DB::transaction(function () use ($image, $offset): void {
            $product = Product::query()->lockForUpdate()->findOrFail($image->product_id);
            $ids = $product->images()->pluck('id')->all();
            $from = array_search($image->id, $ids, true);
            $to = max(0, min(count($ids) - 1, (int) $from + $offset));
            array_splice($ids, (int) $from, 1);
            array_splice($ids, $to, 0, [$image->id]);

            foreach ($ids as $position => $id) {
                ProductImage::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $product = Product::query()->lockForUpdate()->findOrFail($image->product_id);
            $image->delete();

            if ($image->is_primary) {
                $product->images()->first()?->update(['is_primary' => true]);
            }

            DB::afterCommit(fn () => Storage::disk($image->disk)->delete($image->path));
        });
    }
}
