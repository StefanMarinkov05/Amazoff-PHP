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

    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);

    // A product with images and no main image has nothing to show in a
    // listing, and leaving that to whoever ticks the box first is how it
    // stays empty.
    expect($image->is_main)->toBeTrue();
});

it('leaves later images unset unless asked', function (): void {
    $product = Product::factory()->create();
    $first = app(AddProductImage::class)->handle($product, imageAttributes(), null);
    $second = app(AddProductImage::class)->handle($product, imageAttributes(), null);

    expect($first->fresh()->is_main)->toBeTrue()
        ->and($second->is_main)->toBeFalse();
});

it('demotes the previous main when a later image asks for it', function (): void {
    $product = Product::factory()->create();
    $first = app(AddProductImage::class)->handle($product, imageAttributes(), null);
    $second = app(AddProductImage::class)->handle($product, imageAttributes(['is_main' => true]), null);

    expect($first->fresh()->is_main)->toBeFalse()
        ->and($second->fresh()->is_main)->toBeTrue()
        ->and($product->productImages()->where('is_main', true)->count())->toBe(1);
});

it('keeps exactly one main image when promoting', function (): void {
    $product = Product::factory()->create();
    $images = collect(range(1, 3))->map(
        fn (): ProductImage => app(AddProductImage::class)->handle($product, imageAttributes(), null)
    );

    app(SetMainProductImage::class)->handle($images[2], null);

    expect($product->productImages()->where('is_main', true)->count())->toBe(1)
        ->and($images[2]->fresh()->is_main)->toBeTrue();
});

it('refuses to add an image to a removed product', function (): void {
    $product = Product::factory()->create();
    $product->delete();

    expect(fn () => app(AddProductImage::class)->handle($product, imageAttributes(), null))
        ->toThrow(RemovedFromCatalogueException::class);
});

it('hands the main flag to a successor when the main image is removed', function (): void {
    $product = Product::factory()->create();
    $main = app(AddProductImage::class)->handle($product, imageAttributes(['sort_order' => 0]), null);
    $next = app(AddProductImage::class)->handle($product, imageAttributes(['sort_order' => 1]), null);

    app(RemoveProductImage::class)->handle($main, null);

    // A product that still has images must still have a main one; dropping
    // the flag with the row would leave the set with none.
    expect($next->fresh()->is_main)->toBeTrue()
        ->and($product->productImages()->count())->toBe(1);
});

it('leaves no main image when the last one goes', function (): void {
    $product = Product::factory()->create();
    $only = app(AddProductImage::class)->handle($product, imageAttributes(), null);

    app(RemoveProductImage::class)->handle($only, null);

    expect($product->productImages()->count())->toBe(0);
});

it('refuses to remove an image a variation points at', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);
    app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
        'image_id' => $image->getKey(),
    ], 0, null);

    // product_variations.image_id is NO ACTION, so without this the database
    // refuses with 1451 — a 500 rather than a message.
    expect(fn () => app(RemoveProductImage::class)->handle($image, null))
        ->toThrow(ProductImageInUseException::class);

    expect(ProductImage::whereKey($image->getKey())->exists())->toBeTrue();
});

it('counts a trashed variation as still pointing at the image', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
        'image_id' => $image->getKey(),
    ], 0, null);
    $variation->delete();

    // A soft-deleted variation still holds the foreign key, so it still
    // causes 1451.
    expect(fn () => app(RemoveProductImage::class)->handle($image, null))
        ->toThrow(ProductImageInUseException::class);
});

it('deletes the uploaded file only after the row is gone', function (): void {
    $product = Product::factory()->create();
    $path = ProductImage::DIRECTORY.'/'.UploadedFile::fake()->image('shoe.jpg')->hashName();
    Storage::disk(ProductImage::DISK)->put($path, 'bytes');

    $image = app(AddProductImage::class)->handle($product, imageAttributes(['path' => $path]), null);
    app(RemoveProductImage::class)->handle($image, null);

    Storage::disk(ProductImage::DISK)->assertMissing($path);
});

it('keeps the file when the removal is refused', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $path = ProductImage::DIRECTORY.'/kept.jpg';
    Storage::disk(ProductImage::DISK)->put($path, 'bytes');

    $image = app(AddProductImage::class)->handle($product, imageAttributes(['path' => $path]), null);
    app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
        'image_id' => $image->getKey(),
    ], 0, null);

    expect(fn () => app(RemoveProductImage::class)->handle($image, null))
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

/*
 * ADR-0007: null is the application acting on its own behalf — a seeder, a
 * fixture loader, a queued job — and skips the policy check, because
 * Gate::allows() with no user denies everything and those callers could not
 * run at all otherwise.
 *
 * These are not authorization tests; they pin the deliberate absence of
 * authorization, so a future change that starts refusing null is a red test
 * and a decision rather than a silently broken seeder. `$actor` carries no
 * default, so reaching this path is always an explicit `null` at the call
 * site — see the ADR's amendment.
 */

it('skips the policy for a null actor when adding an image', function (): void {
    $product = Product::factory()->create();

    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);

    expect($image->exists)->toBeTrue()
        ->and($image->is_main)->toBeTrue();
});

it('skips the policy for a null actor when promoting an image', function (): void {
    $product = Product::factory()->create();
    app(AddProductImage::class)->handle($product, imageAttributes(['sort_order' => 0]), null);
    $second = app(AddProductImage::class)->handle($product, imageAttributes(['sort_order' => 1]), null);

    app(SetMainProductImage::class)->handle($second, null);

    expect($second->fresh()->is_main)->toBeTrue()
        ->and($product->productImages()->where('is_main', true)->count())->toBe(1);
});

it('skips the policy for a null actor when removing an image', function (): void {
    $product = Product::factory()->create();
    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);

    app(RemoveProductImage::class)->handle($image, null);

    expect($product->productImages()->count())->toBe(0);
});
