<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Category → descendant resolution. The tree is genuinely deep — 4 levels
 * measured in the seeded catalogue (Clothing → Men → Tops → T-Shirts) — so
 * "select a parent, see every product under it" needs a real walk, not one
 * `children()` hop; a sample of the shallow branches alone reads as 2
 * levels and is wrong.
 *
 * A product can sit on a non-leaf category directly (Garden holds 3 of its
 * own alongside Mowers' and Watering's), so "every product of this node"
 * means self **plus** every descendant, not descendants only.
 *
 * A function, not an Action — it writes nothing. Called by
 * `ProductList::applyFilters()` for the actual filter, and by the site
 * header's mega-menu for the one-level-deep hover list, which needs only
 * direct children, not the full walk.
 */
final class ResolveCategoryFamily
{
    /**
     * `$category`'s own id plus every descendant id at any depth.
     *
     * Loads the whole table once (173 rows in the seeded catalogue — cheap
     * at any realistic size for a catalogue's own category count) and walks
     * an in-memory adjacency map rather than issuing one query per level or
     * a recursive CTE. Simpler to read, and this table's size is the reason
     * that trade is fine: a walk over the full table is one query regardless
     * of how deep the selected branch goes.
     *
     * @return list<int>
     */
    public static function selfAndDescendantIds(ProductCategory $category): array
    {
        $childrenOf = self::childrenByParentId();

        $ids = [$category->id];
        $frontier = [$category->id];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $parentId) {
                foreach ($childrenOf->get($parentId, []) as $childId) {
                    $ids[] = $childId;
                    $next[] = $childId;
                }
            }

            $frontier = $next;
        }

        return $ids;
    }

    /**
     * Top-level categories with their direct children only — the mega-menu
     * shows one level on hover, not the full tree, so this does not walk
     * past it.
     *
     * @return Collection<int, ProductCategory>
     */
    public static function topLevelWithChildren(): Collection
    {
        return ProductCategory::query()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('name')
            ->get();
    }

    /**
     * `$categories` (already filtered to the ones worth showing — a
     * non-empty family, per `ProductList::categories()`) reordered so every
     * category sits immediately after its parent, indented by depth, with
     * siblings alphabetical — a tree read top to bottom, not a flat
     * alphabetised dump of all 173 rows a customer has no way to parse.
     *
     * Never orphans a row: a category's family count can only be *smaller*
     * than the sum of what stays after it is added up the chain (every
     * ancestor's own family sum includes it), so if a category's count is
     * positive, every ancestor up to the root has a positive count too and
     * is already present in `$categories` — nothing in the input set is
     * ever missing its own parent here.
     *
     * Returns an `Eloquent\Collection`, not the plain `Support\Collection`
     * every other method here builds — `ProductList::categories()` declares
     * that stricter return type, and a plain `Collection` cannot satisfy it
     * even though every item is still a `ProductCategory` model.
     *
     * @param  Collection<int, ProductCategory>  $categories
     * @return EloquentCollection<int, ProductCategory>
     */
    public static function orderedTreeWithDepth(Collection $categories): EloquentCollection
    {
        $byId = $categories->keyBy('id');

        $childrenOfInSet = $categories
            ->filter(fn (ProductCategory $category): bool => $category->parent_id !== null && $byId->has($category->parent_id))
            ->groupBy('parent_id')
            ->map(fn (Collection $group): Collection => $group->sortBy('name')->values());

        $ordered = new EloquentCollection;

        $walk = function (ProductCategory $category, int $depth) use (&$walk, &$ordered, $childrenOfInSet): void {
            $category->setAttribute('depth', $depth);
            $ordered->push($category);

            foreach ($childrenOfInSet->get($category->id, new Collection) as $child) {
                $walk($child, $depth + 1);
            }
        };

        $topLevel = $categories
            ->filter(fn (ProductCategory $category): bool => $category->parent_id === null || ! $byId->has($category->parent_id))
            ->sortBy('name');

        foreach ($topLevel as $top) {
            $walk($top, 0);
        }

        return $ordered;
    }

    /**
     * `parent_id => [child id, ...]` for the whole table, one query.
     *
     * `groupBy()` keys as `int|string` regardless of the grouped column's
     * real type — Eloquent's own inference, not something this method
     * narrows further — so the key type here is honest about that rather
     * than asserting `int` and having Larastan catch the mismatch anyway.
     *
     * @return Collection<int|string, array<int>>
     */
    private static function childrenByParentId(): Collection
    {
        return ProductCategory::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn (Collection $group): array => $group->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
