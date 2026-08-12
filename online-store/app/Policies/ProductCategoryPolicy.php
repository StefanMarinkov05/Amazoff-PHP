<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;

/** See BrandPolicy for why these check permissions rather than roles. */
class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_product_category');
    }

    public function view(User $user, ProductCategory $productCategory): bool
    {
        return $user->can('view_product_category');
    }

    public function create(User $user): bool
    {
        return $user->can('create_product_category');
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        return $user->can('update_product_category');
    }

    /**
     * Whether the category may be deleted at all — because it has no
     * products or subcategories — is a separate question from whether this
     * user may delete categories, and does not belong here. It currently
     * lives in ProductCategoriesTable as a `visible()` closure; per
     * CLAUDE.md it belongs in an Action so the storefront and the panel
     * cannot disagree about it. Tracked in misc/open-review-findings.md.
     */
    public function delete(User $user, ProductCategory $productCategory): bool
    {
        return $user->can('delete_product_category');
    }
}
