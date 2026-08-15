<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\RemoveProductImage;
use App\Actions\Catalogue\SetMainProductImage;
use App\Exceptions\ProductImageInUseException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
 * A product with images has exactly one main image. MySQL cannot express that
 * — no partial unique index, and ADR-0004 rejected triggers — and it is
 * verified unenforced: the database accepts two is_main rows for one product.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
    Storage::fake(ProductImage::DISK);
});

function imageAttributes(array $overrides = []): array
{
    return array_merge([
        'path' => ProductImage::DIRECTORY.'/'.fake()->unique()->slug(2).'.jpg',
        'alt_text' => 'A product',
        'sort_order' => 0,
    ], $overrides);
}

it('makes the first image main whether or not it was asked for', function (): void {
    $product = Product::factory()->create();

    $image = app(AddProductImage::class)->handle($product, imageAttributes());

    // A product with images and no main image has nothing to show in a
    // listing, and leaving that to whoever ticks the box first is how it
    // stays empty.
    expect($image->is_main)->toBeTrue();
});

it('leaves later images unset unless asked', function (): void {
    $product = Product::factory()->create();
    $first = app(AddProductImage::class)->handle($product, imageAttributes());
    $second = app(AddProductImage::class)->handle($product, imageAttributes());

    expect($first->fresh()->is_main)->toBeTrue()
        ->and($second->is_main)->toBeFalse();
});

it('demotes the previous main when a later image asks for it', function (): void {
    $product = Product::factory()->create();
    $first = app(AddProductImage::class)->handle($product, imageAttributes());
    $second = app(AddProductImage::class)->handle($product, imageAttributes(['is_main' => true]));

    expect($first->fresh()->is_main)->toBeFalse()
        ->and($second->fresh()->is_main)->toBeTrue()
        ->and($product->productImages()->where('is_main', true)->count())->toBe(1);
});

it('keeps exactly one main image when promoting', function (): void {
    $product = Product::factory()->create();
    $images = collect(range(1, 3))->map(
        fn (): ProductImage => app(AddProductImage::class)->handle($product, imageAttributes())
    );

    app(SetMainProductImage::class)->handle($images[2]);

    expect($product->productImages()->where('is_main', true)->count())->toBe(1)
        ->and($images[2]->fresh()->is_main)->toBeTrue();
});

it('refuses to add an image to a removed product', function (): void {
    $product = Product::factory()->create();
    $product->delete();

    expect(fn () => app(AddProductImage::class)->handle($product, imageAttributes()))
        ->toThrow(RemovedFromCatalogueException::class);
});

it('hands the main flag to a successor when the main image is removed', function (): void {
    $product = Product::factory()->create();
    $main = app(AddProductImage::class)->handle($product, imageAttributes(['sort_order' => 0]));
    $next = app(AddProductImage::class)->handle($product, imageAttributes(['sort_order' => 1]));

    app(RemoveProductImage::class)->handle($main);

    // A product that still has images must still have a main one; dropping
    // the flag with the row would leave the set with none.
    expect($next->fresh()->is_main)->toBeTrue()
        ->and($product->productImages()->count())->toBe(1);
});

it('leaves no main image when the last one goes', function (): void {
    $product = Product::factory()->create();
    $only = app(AddProductImage::class)->handle($product, imageAttributes());

    app(RemoveProductImage::class)->handle($only);

    expect($product->productImages()->count())->toBe(0);
});

it('refuses to remove an image a variation points at', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $image = app(AddProductImage::class)->handle($product, imageAttributes());
    app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
        'image_id' => $image->getKey(),
    ]);

    // product_variations.image_id is NO ACTION, so without this the database
    // refuses with 1451 — a 500 rather than a message.
    expect(fn () => app(RemoveProductImage::class)->handle($image))
        ->toThrow(ProductImageInUseException::class);

    expect(ProductImage::whereKey($image->getKey())->exists())->toBeTrue();
});

it('counts a trashed variation as still pointing at the image', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $image = app(AddProductImage::class)->handle($product, imageAttributes());
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
        'image_id' => $image->getKey(),
    ]);
    $variation->delete();

    // A soft-deleted variation still holds the foreign key, so it still
    // causes 1451.
    expect(fn () => app(RemoveProductImage::class)->handle($image))
        ->toThrow(ProductImageInUseException::class);
});

it('deletes the uploaded file only after the row is gone', function (): void {
    $product = Product::factory()->create();
    $path = ProductImage::DIRECTORY.'/'.UploadedFile::fake()->image('shoe.jpg')->hashName();
    Storage::disk(ProductImage::DISK)->put($path, 'bytes');

    $image = app(AddProductImage::class)->handle($product, imageAttributes(['path' => $path]));
    app(RemoveProductImage::class)->handle($image);

    Storage::disk(ProductImage::DISK)->assertMissing($path);
});

it('keeps the file when the removal is refused', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $path = ProductImage::DIRECTORY.'/kept.jpg';
    Storage::disk(ProductImage::DISK)->put($path, 'bytes');

    $image = app(AddProductImage::class)->handle($product, imageAttributes(['path' => $path]));
    app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
        'image_id' => $image->getKey(),
    ]);

    expect(fn () => app(RemoveProductImage::class)->handle($image))
        ->toThrow(ProductImageInUseException::class);

    // The file is deleted after the commit, so a refusal must leave it. Row
    // intact and file gone is the one combination nothing can repair.
    Storage::disk(ProductImage::DISK)->assertExists($path);
});

it('denies an actor without update_product', function (): void {
    $product = Product::factory()->create();
    $actor = catalogueActor('view_product');

    expect(fn () => app(AddProductImage::class)->handle($product, imageAttributes(), $actor))
        ->toThrow(AuthorizationException::class);

    expect($product->productImages()->count())->toBe(0);
});

it('allows an actor holding update_product', function (): void {
    $product = Product::factory()->create();
    $actor = catalogueActor('update_product');

    $image = app(AddProductImage::class)->handle($product, imageAttributes(), $actor);

    expect($image->exists)->toBeTrue();
});
