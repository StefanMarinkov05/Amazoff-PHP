<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\SetVariationImages;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ResolveVariationImage;
use Illuminate\Support\Facades\Storage;

/*
 * Which image represents a variation, resolved at read time and never stored —
 * the same shape as ResolveVariationPrice. The variation's own gallery wins;
 * otherwise it inherits the product's main image. ADR-0013.
 */

beforeEach(function (): void {
    Storage::fake(ProductImage::DISK);
});

function galleryImage(Product $product): ProductImage
{
    return app(AddProductImage::class)->handle($product, [
        'path' => ProductImage::DIRECTORY.'/'.fake()->unique()->slug(2).'.jpg',
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
        ->toBe(Storage::disk(ProductImage::DISK)->url($image->path));
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
