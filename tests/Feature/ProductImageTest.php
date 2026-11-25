<?php

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

function uploadImage(Product $product, ?int $variantId = null): ProductImage
{
    test()->post("/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('foto.png', 2000, 1000), 'product_variant_id' => $variantId])->assertSessionHasNoErrors();

    return ProductImage::query()->latest('id')->firstOrFail();
}

test('stores an optimized image in the private company folder', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $image = uploadImage($product);

    expect($image)->mime_type->toBe('image/webp')->is_primary->toBeTrue()->path->toStartWith("companies/{$company->id}/products/{$product->id}/");
    expect([$image->width, $image->height])->toBe([1600, 800]);
    Storage::disk('local')->assertExists($image->path);
    $this->get("/products/{$product->id}/images/{$image->id}")->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('discards metadata embedded in the original file', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $jpeg = (string) UploadedFile::fake()->image('foto.jpg', 20, 20)->getContent();
    $contents = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', 2 + strlen('SEGREDO<?php')).'SEGREDO<?php'.substr($jpeg, 2);

    $this->post("/products/{$product->id}/images", ['image' => UploadedFile::fake()->createWithContent('foto.jpg', $contents)])->assertSessionHasNoErrors();

    expect(Storage::disk('local')->get(ProductImage::query()->sole()->path))->not->toContain('SEGREDO');
});

test('rejects files that are not accepted images', function (Closure $file) {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->post("/products/{$product->id}/images", ['image' => $file()])->assertSessionHasErrors('image');

    expect(ProductImage::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'php script' => [fn () => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')],
    'script named as image' => [fn () => UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo 1;')],
    'image with executable extension' => [fn () => UploadedFile::fake()->createWithContent('foto.php', (string) UploadedFile::fake()->image('foto.png')->getContent())],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('foto.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
    'truncated png' => [fn () => UploadedFile::fake()->createWithContent('foto.png', substr((string) UploadedFile::fake()->image('foto.png', 50, 50)->getContent(), 0, 40))],
    'oversized' => [fn () => UploadedFile::fake()->image('foto.png')->size(6000)],
]);

test('sets the primary image, reorders and promotes another when the primary is deleted', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $first = uploadImage($product);
    $second = uploadImage($product);
    $url = "/products/{$product->id}/images";

    $this->patch("{$url}/{$second->id}", ['is_primary' => true])->assertRedirect();
    expect([$first->refresh()->is_primary, $second->refresh()->is_primary])->toBe([false, true]);

    $this->patch("{$url}/{$second->id}", ['direction' => 'previous'])->assertRedirect();
    expect($product->images()->pluck('id')->all())->toBe([$second->id, $first->id]);

    $this->delete("{$url}/{$second->id}")->assertRedirect();
    expect($first->refresh()->is_primary)->toBeTrue();
    Storage::disk('local')->assertMissing($second->path);
});

test('an image only accepts variations of its own product', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->variable()->create(['company_id' => $company->id]);
    $variant = ProductVariant::factory()->for($product)->create();
    $foreign = ProductVariant::factory()->create();
    $image = uploadImage($product, $variant->id);

    expect($image->product_variant_id)->toBe($variant->id);
    $this->patch("/products/{$product->id}/images/{$image->id}", ['product_variant_id' => $foreign->id])->assertSessionHasErrors('product_variant_id');
});

test('limits the number of images and leaves no orphan file', function () {
    config(['products.images.maximum_per_product' => 1]);
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    uploadImage($product);

    $this->post("/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('b.png')])->assertSessionHasErrors('image');

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

test('images are not exposed to other companies or guests', function () {
    $company = Company::factory()->create();
    actingAsProductsMember($company);
    $product = Product::factory()->create(['company_id' => $company->id]);
    $url = "/products/{$product->id}/images/".uploadImage($product)->id;

    actingAsProductsMember(Company::factory()->create());
    $this->get($url)->assertNotFound();

    auth()->logout();
    $this->get($url)->assertRedirect('/login');
});
