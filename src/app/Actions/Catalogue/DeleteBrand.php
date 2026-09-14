<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\BrandCannotBeDeletedException;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes a brand, refusing while a product still references it.
 *
 * `products.brand_id`'s foreign key already blocks this at the database as
 * error 1451, a raw `QueryException`. This Action turns that into a message
 * an administrator can act on, thrown from inside a lock rather than left
 * to the database: a product assigned to this brand in the same instant is
 * either already visible to the count or is itself blocked waiting on the
 * lock — see `explanation/concurrency-and-locking.md`.
 *
 * Authorizes `delete_brand`. Locks `brands`.
 */
final class DeleteBrand
{
    /**
     * @throws BrandCannotBeDeletedException
     */
    public function handle(Brand $brand, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $brand);
        }

        DB::transaction(function () use ($brand): void {
            /** @var Brand $locked */
            $locked = Brand::query()->lockForUpdate()->findOrFail($brand->getKey());

            $products = $locked->products()->count();

            if ($products > 0) {
                throw BrandCannotBeDeletedException::hasProducts($locked, $products);
            }

            $locked->delete();
        });
    }
}
