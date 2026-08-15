<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Promotes one image to main and demotes the rest, in a single statement.
 *
 * A product with images has exactly one main image. MySQL cannot express that
 * — no partial unique index over `is_main = 1`, and ADR-0004 rejected triggers
 * — and it is verified unenforced: the database accepts two `is_main` rows.
 *
 * Owns the rule for the whole set. `AddProductImage` and `RemoveProductImage`
 * compose this rather than repeating it, so "exactly one" is decided once.
 *
 * ## Why there is no lock, unlike the rest of this namespace
 *
 * This reads nothing to decide anything — it is a blind write, so there is no
 * check-then-act window for a lock to close. Expressed as one `UPDATE`, the
 * invariant holds by construction: the statement is atomic, so no interleaving
 * can leave two rows set.
 *
 * The obvious two-statement form (demote the others, then promote this one) is
 * *also* correct, because InnoDB row-locks every row an UPDATE touches until
 * commit and each transaction touches both rows — but two concurrent
 * promotions can then acquire those rows in opposite order and deadlock, which
 * surfaces as error 1213 and a 500. One statement cannot.
 *
 * Authorizes `update_product` via `ProductImagePolicy` — managing a product's
 * images is editing that product. See `reference/product-write-rules.md`.
 */
final class SetMainProductImage
{
    public function handle(ProductImage $image, ?User $actor = null): ProductImage
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $image);
        }

        // `is_main = (id = N)` evaluates per row: true for this image, false
        // for its siblings. The cast to int is what makes the raw fragment
        // injection-safe; nothing else here is interpolated.
        ProductImage::query()
            ->where('product_id', $image->product_id)
            ->update(['is_main' => DB::raw('`id` = '.(int) $image->getKey())]);

        return $image->refresh();
    }
}
