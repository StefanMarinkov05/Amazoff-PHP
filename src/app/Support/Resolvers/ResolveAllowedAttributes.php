<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\Attribute;
use App\Models\ProductCategory;
use Illuminate\Support\Collection;

/**
 * Which attributes a category may use as a variation axis.
 *
 * `attribute_product_category` is an allow-list an admin opts an attribute
 * into, not a mandatory classification: an attribute with no rows there is
 * allowed for every category, exactly as every attribute behaved before this
 * table existed. A category inherits its ancestors' allow-lists too — an
 * attribute scoped to "Clothing" is available to "Clothing > Men > Tops"
 * without being re-scoped at every depth — the same self-plus-family
 * reasoning `ResolveCategoryFamily` gives for "select a parent, see every
 * product under it", walked upward here instead of downward.
 *
 * A function, not an Action — it writes nothing.
 */
final class ResolveAllowedAttributes
{
    /**
     * `$category`'s own allow-list plus every ancestor's, unioned with every
     * unrestricted attribute (no row in `attribute_product_category` at
     * all).
     *
     * @return list<int>
     */
    public static function forCategory(ProductCategory $category): array
    {
        $ancestorIds = self::selfAndAncestorIds($category);

        /** @var Collection<int, int> $scoped */
        $scoped = Attribute::query()
            ->whereHas('productCategories', fn ($query) => $query->whereIn('product_categories.id', $ancestorIds))
            ->pluck('id');

        /** @var Collection<int, int> $unrestricted */
        $unrestricted = Attribute::query()
            ->whereDoesntHave('productCategories')
            ->pluck('id');

        return array_values($scoped->merge($unrestricted)->unique()->all());
    }

    /**
     * `$category`'s own id plus every ancestor's, walking `parent_id` up.
     * The tree is 4 levels deep at most in the seeded catalogue
     * (`ResolveCategoryFamily`'s own docblock), so this is a handful of
     * lookups against an in-memory map, not a query per level.
     *
     * @return list<int>
     */
    private static function selfAndAncestorIds(ProductCategory $category): array
    {
        /** @var Collection<int, ProductCategory> $byId */
        $byId = ProductCategory::query()->get(['id', 'parent_id'])->keyBy('id');

        $ids = [$category->id];
        $current = $byId->get($category->id);

        while ($current instanceof ProductCategory && $current->parent_id !== null) {
            $ids[] = $current->parent_id;
            $current = $byId->get($current->parent_id);
        }

        return $ids;
    }
}
