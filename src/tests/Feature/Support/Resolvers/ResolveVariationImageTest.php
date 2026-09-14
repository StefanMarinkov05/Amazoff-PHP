<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\SetVariationImages;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Resolvers\ResolveVariationImage;
use Illuminate\Support\Facades\Storage;

/*
 * Which image represents a variation, resolved at read time and never stored —
 * the same shape as ResolveVariationPrice. The variation's own gallery wins;
 * otherwise it inherits the product's main image. ADR-0013.
 */

beforeEach(function (): void {
    Storage::fake(ProductImage::uploadDisk());
});

/**
 * A real file on the fake disk, not only a row — `AddProductImage` only
 * ever writes the row (the actual upload happens earlier, through
 * Filament's own upload field, before this Action runs), and
 * `ProductImage::servableUrl()` now checks the disk, not only the row, so
 * a caller of this helper needs the file to genuinely exist the same way
 * production data would.
 */
function galleryImage(Product $product): ProductImage
{
    $path = ProductImage::DIRECTORY.'/'.fake()->unique()->slug(2).'.jpg';
    Storage::disk(ProductImage::uploadDisk())->put($path, 'fake-image-bytes');

    return app(AddProductImage::class)->handle($product, [
        'path' => $path,
        'alt_text' => null,
        'sort_order' => 0,
    ], null);
}

it('returns the variation gallery image at position one', function (): void {
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);
    $first = galleryImage($product);
    $second = galleryImage($product);

    app(SetVariationImages::class)->handle($variation, [$second->id, $first->id], null);

    expect(ResolveVariationImage::current($variation)?->id)->toBe($second->id);
});

it('falls back to the product main image when the variation has neither', function (): void {
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);
    $main = galleryImage($product);
    galleryImage($product);

    // AddProductImage makes the first image main whether or not it was asked
    // for, so $main is the one a listing would already be showing.
    expect(ResolveVariationImage::current($variation)?->id)->toBe($main->id);
});

it('returns null when the product has no images at all', function (): void {
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    expect(ResolveVariationImage::current($variation))->toBeNull();
});

it('urlOrDefault resolves the gallery image to a storage URL', function (): void {
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);
    $image = galleryImage($product);

    app(SetVariationImages::class)->handle($variation, [$image->id], null);

    expect(ResolveVariationImage::urlOrDefault($variation))
        ->toBe(Storage::disk(ProductImage::uploadDisk())->url($image->path));
});

it('urlOrDefault falls back to the placeholder asset when nothing resolves', function (): void {
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    // current() is legally null here — no images anywhere on the product —
    // and urlOrDefault's whole reason to exist is never handing that null
    // to a caller that only wants something to render.
    expect(ResolveVariationImage::current($variation))->toBeNull()
        ->and(ResolveVariationImage::urlOrDefault($variation))
        ->toBe(asset(ResolveVariationImage::DEFAULT_PATH));
});

/*
 * A product_images row existing is not the same guarantee as its file
 * existing — an admin action, a manual disk change, or a database restore
 * against a fresh disk can all leave a row with a path nothing is at. This
 * is not hypothetical: confirmed live against the running app (a row
 * created pointing at a path that was never a real file) with every one of
 * the catalogue card, product gallery, and cart rendering the browser's
 * native broken-image icon before this fix — ResolveVariationImage's own
 * "no row" fallback never triggered, because a row did exist.
 */

it('urlOrDefault falls back to the placeholder when the row exists but its file does not', function (): void {
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    // A row via the real Action, but never written to the fake disk — the
    // exact state a broken upload, a manual DB edit, or a restored database
    // against a fresh disk leaves.
    $broken = app(AddProductImage::class)->handle($product, [
        'path' => ProductImage::DIRECTORY.'/never-actually-written.jpg',
        'alt_text' => null,
        'sort_order' => 0,
    ], null);

    app(SetVariationImages::class)->handle($variation, [$broken->id], null);

    expect(ResolveVariationImage::current($variation)?->id)->toBe($broken->id)
        ->and(ResolveVariationImage::urlOrDefault($variation))
        ->toBe(asset(ResolveVariationImage::DEFAULT_PATH));
});

it('ProductImage::servableUrl falls back for a row whose file is missing', function (): void {
    $product = Product::factory()->create();

    $broken = app(AddProductImage::class)->handle($product, [
        'path' => ProductImage::DIRECTORY.'/also-never-written.jpg',
        'alt_text' => null,
        'sort_order' => 0,
    ], null);

    expect($broken->servableUrl())->toBe(asset('images/default-product.png'));
});

it('ProductImage::servableUrl resolves normally when the file is really there', function (): void {
    $product = Product::factory()->create();
    $real = galleryImage($product);

    expect($real->servableUrl())
        ->toBe(Storage::disk(ProductImage::uploadDisk())->url($real->path));
});
