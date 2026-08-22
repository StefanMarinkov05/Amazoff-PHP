<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\ProductCategoryCannotBeDeletedException;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes a category, refusing while it still has subcategories or products.
 *
 * Both checks already exist somewhere: `parent_id`'s foreign key blocks the
 * first at the database, and `ProductCategoriesTable` checked the second in
 * a `visible()`/`disabled()` closure — read once, when the row rendered, not
 * when the click landed. Neither gave the storefront a rule to call, and
 * neither turned a refusal into a message rather than a 500 or a disabled
 * button with no explanation. `ProductCategoryPolicy::delete()` already
 * named this as belonging in an Action.
 *
 * Locks the category row before counting either dependency, so a
 * subcategory or product created for it in the same instant is either
 * already visible to this count or is itself blocked waiting on the lock —
 * InnoDB takes a lock on the referenced row for a child insert's own
 * foreign-key check, which is what makes the re-read meaningful rather than
 * decorative. See `explanation/concurrency-and-locking.md`.
 *
 * Authorizes `delete_product_category`. Locks `product_categories`.
 */
final class DeleteProductCategory
{
    /**
     * @throws ProductCategoryCannotBeDeletedException
     */
    public function handle(ProductCategory $category, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $category);
        }

        DB::transaction(function () use ($category): void {
            $locked = ProductCategory::query()->lockForUpdate()->findOrFail($category->getKey());

            $children = $locked->children()->count();

            if ($children > 0) {
                throw ProductCategoryCannotBeDeletedException::hasChildren($locked, $children);
            }

            $products = $locked->products()->count();

            if ($products > 0) {
                throw ProductCategoryCannotBeDeletedException::hasProducts($locked, $products);
            }

            $locked->delete();
        });
    }
}
