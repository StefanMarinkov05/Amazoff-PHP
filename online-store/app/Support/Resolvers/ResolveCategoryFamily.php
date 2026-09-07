<?php

declare(strict_types=1);

namespace App\Support\Resolvers;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
     * Whether reparenting `$category` under `$parentId` would make the tree
     * cyclic — i.e. whether the proposed parent is `$category` itself or one
     * of its own descendants.
     *
     * `parent_id` is a self-referencing foreign key, and no foreign key can
     * express acyclicity: the database happily accepts A→B→A, after which
     * every upward walk (`ancestryOf()`) and every downward walk
     * (`selfAndDescendantIds()`) is walking a loop. One of ADR-0005's
     * un-constrainable invariants, enforced by `MoveProductCategory`.
     *
     * A category being *created* cannot fail this — it has no descendants
     * yet — which is why only the edit path guards it.
     */
    public static function wouldCreateCycle(ProductCategory $category, ?int $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }

        return in_array($parentId, self::selfAndDescendantIds($category), true);
    }

    /**
     * `$category`'s ancestry, root first, ending with `$category` itself —
     * `[Clothing, Men, Tops, T-Shirts]`. The admin edit page renders this as
     * a breadcrumb so a category's place in the tree is visible without
     * opening its parent, and its parent's parent, one at a time.
     *
     * Walks `parent_id` upward over one in-memory map, the same trade
     * `selfAndDescendantIds()` makes walking downward: one query regardless
     * of depth, on a table small enough for that to be the cheaper read.
     *
     * @return list<ProductCategory>
     */
    public static function ancestryOf(ProductCategory $category): array
    {
        /** @var Collection<int, ProductCategory> $byId */
        $byId = ProductCategory::query()->get()->keyBy('id');

        $chain = [];
        $current = $byId->get($category->id);

        // Bounded by the map's own size rather than trusting parent_id to be
        // acyclic: nothing in the schema prevents a cycle, and a cycle here
        // would otherwise hang the edit page rather than render it oddly.
        $guard = $byId->count() + 1;

        while ($current instanceof ProductCategory && $guard-- > 0) {
            array_unshift($chain, $current);

            $current = $current->parent_id === null
                ? null
                : $byId->get($current->parent_id);
        }

        return $chain;
    }

    /**
     * The full category table, tree-ordered with depth — the same walk as
     * {@see orderedTreeWithDepth()} but unfiltered, for callers that need
     * every category rather than only the ones already holding a product.
     * The admin product form is the current caller: an admin assigning a
     * category is choosing where a *new* product goes, so a category with
     * zero products today is still a legal, expected choice.
     *
     * @return EloquentCollection<int, ProductCategory>
     */
    public static function allOrderedWithDepth(): EloquentCollection
    {
        return self::orderedTreeWithDepth(ProductCategory::query()->get());
    }

    /**
     * {@see allOrderedWithDepth()} as `id => label` pairs for a Filament
     * `Select::options()`, each label indented by depth so the dropdown
     * reads as a tree instead of an alphabetised flat dump of all 173 rows
     * — the same lookup problem the storefront sidebar had before
     * {@see orderedTreeWithDepth()}, now solved once and reused here rather
     * than re-solved separately for the admin panel.
     *
     * @return array<int, string>
     */
    public static function selectOptions(): array
    {
        return self::allOrderedWithDepth()
            ->mapWithKeys(function (ProductCategory $category): array {
                $depth = $category->getAttribute('depth');

                if (! is_int($depth)) {
                    throw new InvalidArgumentException('ProductCategory depth attribute was not an integer.');
                }

                return [$category->id => str_repeat('— ', $depth).$category->name];
            })
            ->all();
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
            ->map(fn (Collection $group): array => $group->pluck('id')->map(function (mixed $id): int {
                if (! is_int($id)) {
                    throw new InvalidArgumentException('ProductCategory id was not an integer.');
                }

                return $id;
            })->all());
    }
}
