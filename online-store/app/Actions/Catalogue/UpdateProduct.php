<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductRequiresVariationException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Updates a product's own columns, refusing any edit that would leave it
 * sellable with nothing to sell.
 *
 * An edit cannot delete a variation, so this does not re-check the invariant
 * for its own sake — it checks the one move that breaks it from this side. A
 * product created with a variation can lose it afterwards, and publishing it
 * then would put a product with no stock row anywhere behind it into the
 * catalogue. §6–7 words the invariant around sellability ("every *sellable*
 * product has at least one variation"), which is why an unavailable product
 * is allowed to sit there with none. `RemoveProductVariation` guards the
 * other side.
 *
 * ## The lock, and why a transaction alone was not enough
 *
 * The check is check-then-act: count the variations, then save. Between the
 * two, another request can remove the last variation, and the product commits
 * as available with none — the exact invariant this Action exists to enforce,
 * lost to a window of microseconds.
 *
 * Wrapping it in a transaction does not fix that. Under `REPEATABLE READ` the
 * count is a consistent nonlocking read served from the MVCC snapshot: it is
 * guaranteed consistent, not guaranteed current, and the UPDATE commits on
 * top of it regardless. This is the same mechanism, in a different table,
 * that `explanation/concurrency-and-locking.md` works through for stock.
 *
 * So the product row is locked before the count, and `RemoveProductVariation`
 * locks the same row. Locking the variations would not help — the two Actions
 * would contend on different rows and neither would wait.
 *
 * The lock is taken unconditionally rather than only when `is_available` is
 * being set. It costs one row for the length of one statement pair, and a
 * branch that decides whether to be safe is a branch that will eventually
 * decide wrong.
 *
 * ## What this still does not fix
 *
 * Two employees saving forms opened at the same time silently revert each
 * other's untouched fields. That window is human think time, not microseconds,
 * and no lock can span it — a PHP request cannot hold a transaction open
 * between rendering a form and receiving it. Closing that needs optimistic
 * concurrency: a version or `updated_at` carried through the form and checked
 * in the UPDATE's WHERE clause. Measured and pinned in
 * `tests/Feature/Actions/Catalogue/ConcurrentProductEditTest.php`.
 *
 * It is an Action under ADR-0007 on the second clause rather than the first:
 * it enforces an invariant the schema cannot express.
 */
final class UpdateProduct
{
    /**
     * @param  array<string, mixed>  $attributes  Product columns to change.
     *
     * @throws ProductRequiresVariationException
     */
    public function handle(Product $product, array $attributes, ?User $actor = null): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $product);
        }

        return DB::transaction(function () use ($product, $attributes): Product {
            // Taken for the lock, not for the value — $product already holds
            // the row. Discarding the result is deliberate.
            Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $product->fill($attributes);

            if ($product->is_available && $product->productVariations()->count() === 0) {
                throw ProductRequiresVariationException::whenMadeAvailable($product);
            }

            $product->save();

            return $product;
        });
    }
}
