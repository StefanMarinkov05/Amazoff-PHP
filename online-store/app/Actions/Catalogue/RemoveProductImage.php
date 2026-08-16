<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductImageInUseException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an image, refusing while a variation points at it and handing the
 * main flag on if it had it.
 *
 * `product_images` does not soft-delete, and `product_variations.image_id` is
 * `NO ACTION`, so removing a referenced image is error 1451. Promoting a
 * successor keeps the listing intact — a product that still has images must
 * still have a main one.
 *
 * Authorizes `update_product` via `ProductImagePolicy`. Locks `products`.
 * reference/product-write-rules.md
 */
final class RemoveProductImage
{
    public function __construct(private readonly SetMainProductImage $setMain) {}

    /**
     * @throws ProductImageInUseException
     */
    public function handle(ProductImage $image, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $image);
        }

        $path = $image->path;

        DB::transaction(function () use ($image, $actor): void {
            Product::query()
                ->whereKey($image->product_id)
                ->lockForUpdate()
                ->first();

            // withTrashed(): a soft-deleted variation still holds the foreign
            // key, so the scope would hide exactly the rows that cause 1451.
            $variations = ProductVariation::withTrashed()
                ->where('image_id', $image->getKey())
                ->count();

            if ($variations > 0) {
                throw new ProductImageInUseException($image, $variations);
            }

            $wasMain = (bool) $image->is_main;

            $image->delete();

            if ($wasMain) {
                $successor = ProductImage::query()
                    ->where('product_id', $image->product_id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                if ($successor !== null) {
                    $this->setMain->handle($successor, $actor);
                }
            }
        });

        // After the commit, never inside it. A rollback would otherwise leave
        // the row intact and the file gone, which is the one combination
        // nothing can repair.
        Storage::disk(ProductImage::DISK)->delete($path);
    }
}
