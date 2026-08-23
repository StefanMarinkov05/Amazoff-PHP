<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\RemoveProductImage;
use App\Actions\Catalogue\SetMainProductImage;
use App\Actions\Catalogue\SetVariationImages;
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

it('removes an image that variations were showing', function (): void {
    $product = Product::factory()->create(['is_available' => false]);
    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);
    $variation = app(AddProductVariation::class)->handle($product, [
        'sku' => fake()->unique()->regexify('[A-Z0-9]{12}'),
    ], 0, null);
    app(SetVariationImages::class)->handle($variation, [$image->getKey()], null);

    app(RemoveProductImage::class)->handle($image, null);

    // Until ADR-0013 this was refused: product_variations.image_id was a
    // NO ACTION foreign key, so the database answered with 1451 and the
    // Action turned that into ProductImageInUseException. The column is gone
    // and the gallery pivot cascades, so the image leaves every gallery it
    // was in instead of blocking on them.
    expect(ProductImage::whereKey($image->getKey())->exists())->toBeFalse()
        ->and($variation->images()->count())->toBe(0);
});

it('deletes the uploaded file only after the row is gone', function (): void {
    $product = Product::factory()->create();
    $path = ProductImage::DIRECTORY.'/'.UploadedFile::fake()->image('shoe.jpg')->hashName();
    Storage::disk(ProductImage::DISK)->put($path, 'bytes');

    $image = app(AddProductImage::class)->handle($product, imageAttributes(['path' => $path]), null);
    app(RemoveProductImage::class)->handle($image, null);

    Storage::disk(ProductImage::DISK)->assertMissing($path);
});

/*
 * There was a fourth removal test here — "keeps the file when the removal is
 * refused" — asserting that a refused RemoveProductImage left the file on
 * disk. It is gone rather than rewritten: ADR-0013 removed the only refusal
 * this Action had, so nothing can reach the branch it covered. The property it
 * protected (delete the file after the commit, never inside it) is still real
 * and still commented in the Action; what no longer exists is a way to
 * exercise it.
 */

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

it('denies an actor without update_product removing an image', function (): void {
    $product = Product::factory()->create();
    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);
    $actor = catalogueActor('view_product');

    expect(fn () => app(RemoveProductImage::class)->handle($image, $actor))
        ->toThrow(AuthorizationException::class);

    expect(ProductImage::whereKey($image->getKey())->exists())->toBeTrue();
});

it('allows an actor holding update_product to remove an image', function (): void {
    $product = Product::factory()->create();
    $image = app(AddProductImage::class)->handle($product, imageAttributes(), null);
    $actor = catalogueActor('update_product');

    app(RemoveProductImage::class)->handle($image, $actor);

    expect(ProductImage::whereKey($image->getKey())->exists())->toBeFalse();
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
