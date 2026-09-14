<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an image and hands the main flag on if it had it.
 *
 * `product_images` does not soft-delete. Promoting a successor keeps the
 * listing intact — a product that still has images must still have a main one.
 *
 * Nothing refuses any more. Until ADR-0013 this guarded against
 * `product_variations.image_id`, a `NO ACTION` foreign key that turned removing
 * a referenced image into error 1451; that column is gone and the variation
 * gallery that replaced it cascades, so removing an image now simply takes it
 * out of every gallery it was in. A gallery membership is not a dependency —
 * the pairing is gone with nothing left to repair.
 *
 * Authorizes `update_product` via `ProductImagePolicy`. Locks `products`.
 * ADR-0013 · reference/write-rules/product.md
 */
final class RemoveProductImage
{
    public function __construct(private readonly SetMainProductImage $setMain) {}

    public function handle(ProductImage $image, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $image);
        }

        // Both captured before the delete: `disk()` reads `path`, and after
        // `$image->delete()` the in-memory model is no longer a safe thing to
        // ask — same reason `$path` itself is captured here rather than below.
        $path = $image->path;
        $disk = $image->disk();

        DB::transaction(function () use ($image, $actor): void {
            Product::query()
                ->whereKey($image->product_id)
                ->lockForUpdate()
                ->first();

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
        Storage::disk($disk)->delete($path);
    }
}
