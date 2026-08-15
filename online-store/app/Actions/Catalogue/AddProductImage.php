<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Adds an image to a product, keeping exactly one of them main.
 *
 * The first image of a product becomes main whether or not it was asked for:
 * a product with images and no main image has nothing to show in a listing,
 * and leaving that to whoever ticks the box first is how it stays empty.
 *
 * Authorizes `update_product` via `ProductImagePolicy`. Locks `products`
 * through `SetMainProductImage` when a promotion is needed. See
 * `reference/product-write-rules.md`.
 */
final class AddProductImage
{
    public function __construct(private readonly SetMainProductImage $setMain) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RemovedFromCatalogueException
     */
    public function handle(Product $product, array $attributes, ?User $actor = null): ProductImage
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', ProductImage::class);
        }

        return DB::transaction(function () use ($product, $attributes, $actor): ProductImage {
            $live = Product::query()->whereKey($product->getKey())->first();

            if ($live === null) {
                throw RemovedFromCatalogueException::product($product);
            }

            $isFirst = $product->productImages()->count() === 0;
            $wantsMain = (bool) ($attributes['is_main'] ?? false);

            // is_main is stripped rather than set: the column defaults to 0,
            // and promotion goes through SetMainProductImage so the
            // demote-siblings step is never skipped.
            $attributes = Arr::except($attributes, ['is_main']);

            /** @var ProductImage $image */
            $image = $product->productImages()->create($attributes);

            if ($isFirst || $wantsMain) {
                $this->setMain->handle($image, $actor);
            }

            return $image->refresh();
        });
    }
}
