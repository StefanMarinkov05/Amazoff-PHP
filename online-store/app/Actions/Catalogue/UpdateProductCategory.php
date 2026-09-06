<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\CategoryCycleException;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\ResolveCategoryFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Updates a category, refusing a reparent that would make the tree cyclic.
 *
 * `ProductCategory` is otherwise a plain lookup table on default Filament
 * CRUD, per ADR-0007's exemption — this Action exists for the same narrow
 * reason `DeleteProductCategory` does: one invariant the schema cannot
 * express. `parent_id` is a self-referencing foreign key, and no foreign key
 * can express acyclicity; the database accepts A→B→A without complaint, and
 * a cycle turns every walk of this relationship — the storefront's category
 * filter, the admin's ancestry breadcrumb, the mega-menu — into an unbounded
 * loop.
 *
 * Only the edit path needs this. A category being created has no descendants
 * yet, so no choice of parent can close a loop through it, which is why
 * `CreateProductCategory` stays on Filament's default create.
 *
 * Locks the category row before walking its descendants: without it two
 * administrators reparenting A under B and B under A concurrently each see a
 * tree in which their own move is legal, and both commit — the classic write
 * skew, and the one shape `write-rules/concurrency.md` says a transaction
 * alone does not close. Locks the *category being moved*, not the proposed
 * parent, because the descendant set being read is the moved category's own.
 *
 * Authorizes `update_product_category`. Locks `product_categories`.
 * ADR-0005 · ADR-0008
 */
final class UpdateProductCategory
{
    /**
     * @param  array<string, mixed>  $attributes  Category columns to change.
     *
     * @throws CategoryCycleException
     */
    public function handle(ProductCategory $category, array $attributes, ?User $actor): ProductCategory
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $category);
        }

        return DB::transaction(function () use ($category, $attributes): ProductCategory {
            /** @var ProductCategory $locked */
            $locked = ProductCategory::query()->lockForUpdate()->findOrFail($category->getKey());

            // Only when the form actually sent the key — a caller renaming a
            // category should not have its parent read as "move to root" for
            // want of the field.
            if (array_key_exists('parent_id', $attributes)) {
                $rawParentId = $attributes['parent_id'];

                // A non-scalar here (an array, an object) is a caller bug —
                // the form only ever sends a scalar or omits the key
                // entirely, and silently coercing an unexpected shape to 0
                // would move the category to root instead of surfacing the
                // actual defect.
                if ($rawParentId !== null && $rawParentId !== '' && ! is_scalar($rawParentId)) {
                    throw new InvalidArgumentException('parent_id must be a scalar or null, '.get_debug_type($rawParentId).' given.');
                }

                $parentId = $rawParentId === null || $rawParentId === ''
                    ? null
                    : (int) $rawParentId;

                if (ResolveCategoryFamily::wouldCreateCycle($locked, $parentId)) {
                    throw new CategoryCycleException(
                        $locked,
                        ProductCategory::query()->findOrFail($parentId),
                    );
                }
            }

            $locked->fill($attributes);
            $locked->save();

            return $locked;
        });
    }
}
