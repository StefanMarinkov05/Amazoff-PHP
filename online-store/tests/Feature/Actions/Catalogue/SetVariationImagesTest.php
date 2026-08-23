<?php

declare(strict_types=1);

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\ForceDeleteProductVariation;
use App\Actions\Catalogue\RemoveProductImage;
use App\Actions\Catalogue\RemoveProductVariation;
use App\Actions\Catalogue\SetVariationImages;
use App\Exceptions\ImageNotOnProductException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
 * A variation's gallery is a many-to-many over the product's own images, so
 * one photograph can sit at a different position on several variations at
 * once. SetVariationImages owns the whole ordered set; nothing else writes the
 * pivot. ADR-0013.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
    Storage::fake(ProductImage::DISK);
});

/**
 * A product, one variation, and a set of product-level images to draw from.
 *
 * @return array{0: Product, 1: ProductVariation, 2: list<ProductImage>}
 */
function productWithImages(int $images = 3): array
{
    $product = Product::factory()->create();
    $variation = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    $created = [];

    for ($i = 0; $i < $images; $i++) {
        $created[] = app(AddProductImage::class)->handle($product, [
            'path' => ProductImage::DIRECTORY.'/'.fake()->unique()->slug(2).'.jpg',
            'alt_text' => 'A product',
            'sort_order' => 0,
        ], null);
    }

    return [$product, $variation, $created];
}

it('writes the gallery in the order it was given', function (): void {
    [, $variation, $images] = productWithImages();

    app(SetVariationImages::class)->handle(
        $variation,
        [$images[2]->id, $images[0]->id, $images[1]->id],
        null,
    );

    // Array index becomes position, contiguous from 1 — the whole set is
    // rewritten every time, so there is never a partial write to renumber.
    expect($variation->images()->pluck('product_images.id')->all())
        ->toBe([$images[2]->id, $images[0]->id, $images[1]->id])
        ->and($variation->images()->get()->pluck('pivot.position')->all())
        ->toBe([1, 2, 3]);
});

it('replaces the whole set rather than merging into it', function (): void {
    [, $variation, $images] = productWithImages();

    app(SetVariationImages::class)->handle($variation, [$images[0]->id, $images[1]->id], null);
    app(SetVariationImages::class)->handle($variation, [$images[2]->id], null);

    expect($variation->images()->pluck('product_images.id')->all())->toBe([$images[2]->id]);
});

it('clears the gallery when given an empty list', function (): void {
    [, $variation, $images] = productWithImages();

    app(SetVariationImages::class)->handle($variation, [$images[0]->id], null);
    app(SetVariationImages::class)->handle($variation, [], null);

    expect($variation->images()->count())->toBe(0);
});

it('collapses a duplicate to its first position', function (): void {
    [, $variation, $images] = productWithImages();

    // The composite primary key would reject the second row outright; the
    // Action collapses first so an administrator submitting the same image
    // twice gets a gallery rather than a QueryException.
    app(SetVariationImages::class)->handle(
        $variation,
        [$images[1]->id, $images[0]->id, $images[1]->id],
        null,
    );

    expect($variation->images()->pluck('product_images.id')->all())
        ->toBe([$images[1]->id, $images[0]->id]);
});

it('lets one image sit at a different position on two variations', function (): void {
    [$product, $first, $images] = productWithImages();
    $second = app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(SetVariationImages::class)->handle($first, [$images[0]->id, $images[1]->id], null);
    app(SetVariationImages::class)->handle($second, [$images[1]->id, $images[0]->id], null);

    // The whole point of the pivot: the file is uploaded once and shared, so
    // the red photographs are one set of rows however many sizes use them.
    expect($first->images()->pluck('product_images.id')->all())->toBe([$images[0]->id, $images[1]->id])
        ->and($second->images()->pluck('product_images.id')->all())->toBe([$images[1]->id, $images[0]->id])
        ->and(ProductImage::count())->toBe(3);
});

it('refuses an image belonging to another product', function (): void {
    [, $variation] = productWithImages();
    $other = Product::factory()->create();
    $foreign = app(AddProductImage::class)->handle($other, [
        'path' => ProductImage::DIRECTORY.'/foreign.jpg',
        'alt_text' => null,
        'sort_order' => 0,
    ], null);

    // No foreign key can express this — the constraint spans a column neither
    // table holds — so it is enforced here or not at all.
    expect(fn () => app(SetVariationImages::class)->handle($variation, [$foreign->id], null))
        ->toThrow(ImageNotOnProductException::class);

    expect($variation->images()->count())->toBe(0);
});

it('refuses a variation that has been soft-deleted since the page loaded', function (): void {
    [$product, $variation, $images] = productWithImages();

    // A second variation, so the last-variation refusal does not fire first
    // and mask what this test is actually asserting.
    app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(RemoveProductVariation::class)->handle($variation, null);

    expect(fn () => app(SetVariationImages::class)->handle($variation, [$images[0]->id], null))
        ->toThrow(RemovedFromCatalogueException::class);
});

it('drops the pivot row when the image is removed', function (): void {
    [, $variation, $images] = productWithImages();

    app(SetVariationImages::class)->handle($variation, [$images[0]->id, $images[1]->id], null);
    app(RemoveProductImage::class)->handle($images[1], null);

    // Cascade, not a refusal. A gallery membership is not a dependency: the
    // pairing is simply gone, with nothing left to repair.
    expect($variation->images()->pluck('product_images.id')->all())->toBe([$images[0]->id]);
});

it('drops the pivot rows when the variation is erased', function (): void {
    [$product, $variation, $images] = productWithImages();
    app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(SetVariationImages::class)->handle($variation, [$images[0]->id], null);
    app(RemoveProductVariation::class)->handle($variation, null);
    app(ForceDeleteProductVariation::class)->handle($variation, null);

    // Erasing a variation orphans nothing: it never owned the images, so the
    // memberships cascade and the assets stay on the product.
    expect(DB::table('product_image_product_variation')->count())->toBe(0)
        ->and(ProductImage::count())->toBe(3);
});

it('keeps a soft-deleted variation gallery intact', function (): void {
    [$product, $variation, $images] = productWithImages();
    app(AddProductVariation::class)->handle($product, variationAttributes(), 0, null);

    app(SetVariationImages::class)->handle($variation, [$images[0]->id], null);
    app(RemoveProductVariation::class)->handle($variation, null);

    // "Made unavailable" is the soft-delete path and it is non-destructive —
    // restoring the variation restores its gallery with it.
    expect(DB::table('product_image_product_variation')
        ->where('product_variation_id', $variation->getKey())
        ->count())->toBe(1);
});

it('denies an actor without update_product_variation', function (): void {
    [, $variation, $images] = productWithImages();
    $actor = catalogueActor('view_product_variation');

    expect(fn () => app(SetVariationImages::class)->handle($variation, [$images[0]->id], $actor))
        ->toThrow(AuthorizationException::class);
});

it('allows an actor holding update_product_variation', function (): void {
    [, $variation, $images] = productWithImages();
    $actor = catalogueActor('update_product_variation');

    app(SetVariationImages::class)->handle($variation, [$images[0]->id], $actor);

    expect($variation->images()->count())->toBe(1);
});
