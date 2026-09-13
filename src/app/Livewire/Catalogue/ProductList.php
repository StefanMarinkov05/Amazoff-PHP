<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\ProductPrice;
use App\Support\Resolvers\ResolveAllowedAttributes;
use App\Support\Resolvers\ResolveCardVariation;
use App\Support\Resolvers\ResolveCategoryFamily;
use App\Support\Resolvers\ResolveProductPrice;
use App\Support\Resolvers\ResolveVariationPrice;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public catalogue — §37 criteria 2 and 3 (browse, search, filter, sort).
 *
 * Every filter is `#[Url]`, so a filtered catalogue is a shareable link and
 * the back button works. Facet counts come from the *other* filters applied,
 * not the full catalogue, so a count of zero is never shown against an option
 * that would produce an empty page.
 */
class ProductList extends Component
{
    use WithPagination;

    /** @var array<string, string> */
    public const SORTS = [
        'created_at' => 'Newest',
        'name' => 'Name',
        'regular_price' => 'Price',
    ];

    // Staff-only, never in SORTS itself — a public const can't be
    // conditional on the viewer, so this stays a separate key checked by
    // isDemoModeAvailable() both where the dropdown renders it and where
    // safeSortBy() accepts it. A guest sending ?sortBy=demo_case_order
    // directly falls back to 'created_at', the same as any other value not
    // in SORTS — the sort has no effect for them, staff view or not.
    public const DEMO_SORT_KEY = 'demo_case_order';

    // Must match DemoShowcaseOrderSeeder::CASES's count — the page size for
    // demo mode, so all 13 cases render on one page rather than splitting a
    // fixed 13-item walkthrough across two.
    private const CASES_COUNT = 13;

    // 1..4: "N stars & up". No "5 & up" tier — indistinguishable in
    // practice from "4 & up" once a product with no reviews is always
    // shown regardless, and Amazon's own rating filter stops at 4.
    /** @var list<int> */
    public const RATING_TIERS = [4, 3, 2, 1];

    #[Url]
    public string $search = '';

    // Slug, not id: the id is an implementation detail, and a URL a customer
    // shares or bookmarks should read as a category name, not a number.
    // The property name and the query-string key deliberately differ —
    // `categorySlug` internally, `?category=` in the address bar.
    #[Url(as: 'category')]
    public ?string $categorySlug = null;

    /**
     * Deliberately `mixed`, not `?int` — same incident as
     * `ProductDetails::$variationId` and `$quantity`: a number too large for
     * PHP to represent as an `int` (`?brandId=99999999999999999999999999999999`)
     * decodes to a `float` during Livewire's `#[Url]` hydration, which runs
     * before any of this class's own code — `?int` refuses the assignment
     * right there, an unhandled 500 from a crafted URL, confirmed live
     * before this fix. `safeBrandId()` normalises it wherever it is read.
     */
    #[Url]
    public mixed $brandId = null;

    #[Url]
    public bool $inStockOnly = false;

    #[Url]
    public bool $onSaleOnly = false;

    // Deliberately untyped, the same reasoning as ProductDetails::$quantity:
    // wire:model sends whatever the client sends, with no server-side
    // ceiling from the number input's own attributes, and Livewire assigns
    // the raw value before any of this class's own code runs. A strictly
    // `?string`-typed property does not throw the way `?int` would on a
    // pathological value, but staying untyped and sanitising explicitly in
    // updatedMinPrice()/updatedMaxPrice() keeps both filters on the same
    // pattern rather than two different ones for two similar risks.
    #[Url]
    public mixed $minPrice = null;

    #[Url]
    public mixed $maxPrice = null;

    // #[Url]-bound and reaches a query — ADR-0014's allow-list rule; only a
    // value in RATING_TIERS is ever accepted, enforced in updatedMinRating().
    // Untyped, not `?int`, for the same reason as $minPrice/$quantity: a
    // radio input's own values are always small ("4", "3", ...), but a
    // crafted request is not bound by what the markup offers, and a number
    // PHP cannot represent as an int would throw at hydration — before the
    // allow-list check in updatedMinRating() ever runs — on a strictly
    // typed property.
    #[Url]
    public mixed $minRating = null;

    /**
     * Descriptive attribute values to filter by — "cotton", "vanilla" — the
     * `attribute_value_product` pivot rather than the variation grid. A
     * product matches when it carries **every** selected value, not any:
     * picking Cotton and Organic means both, which is what a shopper
     * narrowing a list expects.
     *
     * `mixed` for the same reason `$minRating` and `$minPrice` are: `#[Url]`
     * hydration assigns the raw request value before any validation runs, so
     * a strictly typed property throws on anything it cannot represent.
     *
     * Every read goes through `safeAttributeValueIds()` rather than casting
     * in place — see that method for why the four readers that once cast for
     * themselves were a bug rather than a duplication. The list is
     * additionally intersected against real, filterable ids in
     * `applyFilters()` — an unknown id narrows nothing rather than erroring.
     */
    #[Url]
    public mixed $attributeValueIds = [];

    #[Url]
    public string $sortBy = 'created_at';

    #[Url]
    public string $sortDir = 'desc';

    /**
     * Toggles one facet value on or off, for the clickable facet buttons —
     * click adds it to `$attributeValueIds`, click again removes it. Values
     * within one attribute are OR-ed (`applyFilters()`), so toggling one on
     * never needs to touch any other selected value, unlike a native
     * `<select>` which replaces its whole selection on every change and
     * cannot bind several independent multi-selects to one shared array
     * without each overwriting the others' picks.
     */
    public function toggleAttributeValue(int $valueId): void
    {
        $selected = collect($this->safeAttributeValueIds());

        $this->attributeValueIds = $selected->contains($valueId)
            ? $selected->reject(fn (int $id): bool => $id === $valueId)->values()->all()
            : $selected->push($valueId)->values()->all();

        $this->resetPage();
    }

    /**
     * The signed-in visitor's wishlisted product ids, one query for the
     * whole grid rather than one per card — the same N+1 discipline
     * CLAUDE.md states for Filament tables applies equally here.
     *
     * @return list<int>
     */
    #[Computed]
    public function wishlistedProductIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $user->wishlistItems()->pluck('product_id')->all();

        return $ids;
    }

    /**
     * No Action: one INSERT or DELETE on one table with no invariant the
     * schema cannot express beyond `UNIQUE(user_id, product_id)`, which the
     * caught violation below already respects rather than checks first —
     * same idempotency discipline `CreateProductReview` uses, on a table
     * with nothing else to enforce.
     */
    public function toggleWishlist(int $productId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect('/login', navigate: true);

            return;
        }

        $existing = WishlistItem::query()
            ->where('user_id', $user->getKey())
            ->where('product_id', $productId)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            try {
                WishlistItem::create(['user_id' => $user->getKey(), 'product_id' => $productId]);
            } catch (UniqueConstraintViolationException) {
                // Already wishlisted by a concurrent click from the same
                // user — nothing to do, the row this click wanted already
                // exists.
            }
        }

        unset($this->wishlistedProductIds);
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function setSortOrder(string $column): void
    {
        $isDemoSort = $column === self::DEMO_SORT_KEY && $this->isDemoModeAvailable();

        if (! $isDemoSort && ! array_key_exists($column, self::SORTS)) {
            return;
        }

        if ($isDemoSort) {
            // A curated sequence has no meaningful reverse direction the
            // way "price" or "name" does — always ascending, no toggle.
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        } elseif ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'name' ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    /** @var list<string> */
    private const FILTER_KEYS = [
        'search', 'categorySlug', 'brandId', 'inStockOnly', 'onSaleOnly',
        'minPrice', 'maxPrice', 'minRating', 'attributeValueIds',
    ];

    public function clearFilters(): void
    {
        $this->reset(self::FILTER_KEYS);
        $this->resetPage();
    }

    public function clearFilter(string $filter): void
    {
        // One chip covers both bounds (`priceRangeLabel()`), so dismissing
        // it has to clear both — resetting only the key the chip happens to
        // be keyed on would leave the other bound silently still applied.
        if ($filter === 'minPrice') {
            $this->reset(['minPrice', 'maxPrice']);
            $this->resetPage();

            return;
        }

        // `attributeValue:N` drops one value from the set rather than
        // clearing all of them — within one attribute the values are now
        // OR-ed, so dismissing one chip narrows the OR group by one option
        // rather than clearing every attribute at once.
        if (str_starts_with($filter, 'attributeValue:')) {
            $target = (int) substr($filter, strlen('attributeValue:'));

            $this->attributeValueIds = collect($this->safeAttributeValueIds())
                ->reject(fn (int $id): bool => $id === $target)
                ->values()
                ->all();

            $this->resetPage();

            return;
        }

        if (in_array($filter, self::FILTER_KEYS, true)) {
            $this->reset($filter);
            $this->resetPage();
        }
    }

    /**
     * The only place `$minPrice`/`$maxPrice` become real, bounded values
     * after Livewire hydrates them as `mixed` — same shape as
     * `ProductDetails::updatedQuantity()`. Non-numeric or negative input
     * resets to "no bound" rather than being partially clamped: there is no
     * sensible nearest-valid price to a string that was never a price. A
     * min above the current max pushes the max up to match, never the
     * reverse — the field the customer just touched wins.
     */
    public function updatedMinPrice(): void
    {
        $this->minPrice = $this->sanitizedPrice($this->minPrice);

        if ($this->minPrice !== null && $this->maxPrice !== null && $this->minPrice > $this->maxPrice) {
            $this->maxPrice = $this->minPrice;
        }
    }

    public function updatedMaxPrice(): void
    {
        $this->maxPrice = $this->sanitizedPrice($this->maxPrice);

        if ($this->maxPrice !== null && $this->minPrice !== null && $this->maxPrice < $this->minPrice) {
            $this->minPrice = $this->maxPrice;
        }
    }

    private function sanitizedPrice(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        // decimal(10,2): the column this compares against, so the bound
        // itself carries the same scale rather than a float's binary
        // rounding disagreeing with it at the edges.
        return number_format((float) $raw, 2, '.', '');
    }

    public function updatedMinRating(): void
    {
        $raw = $this->minRating;

        // A radio's own value is a string ("4"), never the int RATING_TIERS
        // holds — strict in_array would reject every legitimate selection,
        // not just a forged one, without this normalisation first.
        $normalized = is_numeric($raw) ? (int) $raw : null;

        $this->minRating = in_array($normalized, self::RATING_TIERS, true) ? $normalized : null;
    }

    /**
     * Normalises `?attributeValueIds[]=` to a clean `list<int>`.
     *
     * Only the shape is fixed here; whether an id exists, is filterable, or
     * is descriptive rather than a variation axis is settled in
     * `applyFilters()` against the database, since that is the only place
     * that knows. A checkbox group posts strings, and a crafted request can
     * post anything at all — including a nested array, which `(int)` would
     * otherwise turn into a warning rather than a value.
     */
    public function updatedAttributeValueIds(): void
    {
        $this->attributeValueIds = $this->safeAttributeValueIds();
    }

    /**
     * `$attributeValueIds` as a clean `list<int>`, whatever arrived.
     *
     * Shared by every reader rather than each casting for itself, because
     * they did and they diverged: `updatedAttributeValueIds()` filtered on
     * `is_numeric` first, while `toggleAttributeValue()`, the chip-removal
     * branch of `clearFilter()`, and `filterableAttributeValueIdsByAttribute()`
     * each ran a bare `(int)` map. That gap is only reachable on the paths an
     * `updated*` hook never runs on — a first page load straight from
     * `#[Url]` hydration — which is exactly where a crafted URL lands.
     *
     * The case it lets through is specific and worse than a crash, because
     * nothing looks wrong: `intval()` of a *non-empty array* is `1`, not `0`,
     * so `?attributeValueIds[0][0]=1&attributeValueIds[0][1]=2` collapsed to
     * the real, filterable id `1` — "Colour: Black" in the seeded catalogue.
     * Confirmed live before this fix: 164 products narrowed to 37 and the
     * page rendered a "Black" chip the visitor never chose. A garbage
     * *string* was always harmless by comparison (`intval('abc') === 0`, an
     * id nothing matches, so the filter falls through unapplied) — it is the
     * array shape alone that fabricates a plausible id out of nothing.
     *
     * Shape only. Whether an id exists, is filterable, or is descriptive
     * rather than a variation axis stays in `applyFilters()`, against the
     * database, which is the only place that knows.
     *
     * @return list<int>
     */
    private function safeAttributeValueIds(): array
    {
        return array_values(collect((array) $this->attributeValueIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all());
    }

    /**
     * Categories with a live count, computed against every filter *except*
     * the category itself — picking one should never make the others read
     * zero. Research consensus: a facet whose count is stale is worse than
     * no count at all, because it promises results that are not there.
     *
     * A category's count includes every descendant's products, not only its
     * own — the same family a selection resolves to in `applyFilters()`.
     * Selecting "Garden" (which has its own 3 products plus 2 in Mowers and
     * 2 in Watering) has to read "(7)" here, or the option promises a count
     * the click does not deliver.
     *
     * One query for the filtered per-category product counts, one for the
     * category list, then a pure in-memory sum over each category's family —
     * `ResolveCategoryFamily` never re-queries per row.
     *
     * Returned depth-first, parent immediately before its own children, each
     * carrying a `depth` attribute the template indents by — a flat
     * alphabetised list of all 173 rows in the seeded catalogue reads as
     * noise; the tree read top to bottom does not.
     *
     * @return Collection<int, ProductCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        // Query builder rather than Eloquent's own pluck(): 'total' is a
        // selectRaw() alias, not a Product property, and Larastan objects to
        // pretending otherwise.
        /** @var SupportCollection<int, int> $countsByRawCategoryId */
        $countsByRawCategoryId = Product::query()
            ->tap(fn (Builder $q) => $this->applyFilters($q, skip: 'categorySlug'))
            ->selectRaw('product_category_id, count(*) as total')
            ->groupBy('product_category_id')
            ->toBase()
            ->pluck('total', 'product_category_id');

        $withCounts = ProductCategory::query()
            ->orderBy('name')
            ->get()
            ->map(function (ProductCategory $category) use ($countsByRawCategoryId): ProductCategory {
                $familyIds = ResolveCategoryFamily::selfAndDescendantIds($category);

                $total = collect($familyIds)->sum(fn (int $id) => $countsByRawCategoryId[$id] ?? 0);

                // withCount() normally attaches this as a read-only virtual
                // attribute; setAttribute() is Eloquent's own public way to
                // do the same thing by hand, which is what this is — a
                // family sum, not a single relation count withCount() could
                // express on its own.
                $category->setAttribute('products_count', $total);

                return $category;
            })
            ->filter(fn (ProductCategory $category) => $category->products_count > 0)
            ->values();

        return ResolveCategoryFamily::orderedTreeWithDepth($withCounts);
    }

    /**
     * The selected category, or null when none is picked or the slug
     * matches nothing — a stale bookmark, a hand-edited URL, a deleted
     * category. Null is a legitimate state, not an error, and every caller
     * treats it as "no category filter" rather than a 404.
     *
     * `#[Computed]` so the three callers that need it (the facet list, the
     * filter chip, `applyFilters()`) share one lookup per request instead
     * of issuing the same `where('slug', ...)` three times.
     */
    #[Computed]
    public function selectedCategory(): ?ProductCategory
    {
        if ($this->categorySlug === null) {
            return null;
        }

        return ProductCategory::query()->where('slug', $this->categorySlug)->first();
    }

    /**
     * The selected ids, narrowed to values that actually exist, belong to a
     * `is_filterable` attribute, and are used descriptively by at least one
     * product. The URL is attacker-controlled, so nothing here trusts it —
     * ADR-0014's allow-list rule, resolved against the database because that
     * is the only place the answer lives.
     *
     * @return list<int>
     */
    private function filterableAttributeValueIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_values($this->filterableAttributeValueIdsByAttribute()
            ->flatten()
            ->all());

        return $ids;
    }

    /**
     * The same allow-listed ids as {@see filterableAttributeValueIds()}, kept
     * grouped by their own attribute — the shape the query actually needs.
     *
     * Reported live: picking Black and White both selected returned zero
     * products, because every value was AND-ed against every other
     * regardless of attribute. Two values of the *same* attribute can never
     * both be true of one variation — "Black AND White" is not a real
     * combination, it is "either colour", the ordinary meaning of checking
     * two boxes in one facet group. AND still applies *across* attributes:
     * Colour=Black AND Material=Cotton narrows, because those can coexist.
     *
     * @return SupportCollection<int|string, array<int>>
     */
    private function filterableAttributeValueIdsByAttribute(): SupportCollection
    {
        $selected = $this->safeAttributeValueIds();

        if ($selected === []) {
            return collect();
        }

        return AttributeValue::query()
            ->whereIn('id', $selected)
            ->whereHas('attribute', function (Builder $attribute): Builder {
                /** @var Builder<Attribute> $attribute */
                return $attribute->where('is_filterable', true);
            })
            ->get(['id', 'attribute_id'])
            ->groupBy('attribute_id')
            ->map(function (Collection $group): array {
                /** @var array<int> $ids */
                $ids = $group->pluck('id')->all();

                return $ids;
            });
    }

    /**
     * The descriptive-value facets worth offering, grouped by attribute
     * name, each with a live count computed against every *other* filter —
     * the same "never show a facet that promises nothing" rule
     * `categories()` follows, and for the same reason.
     *
     * Only `is_filterable` attributes, and only values at least one visible
     * product actually carries: a facet with no products behind it is noise
     * on a page whose whole job is narrowing.
     *
     * @return SupportCollection<string, SupportCollection<int, AttributeValue>>
     */
    #[Computed]
    public function attributeFacets(): SupportCollection
    {
        // No category picked: only the generic filters (brand, price,
        // rating, stock, sale) apply. Colour and Size mean nothing across a
        // catalogue that also holds power tools and moisturiser, and a
        // sidebar offering every attribute in the shop at once is the flat
        // dump this whole feature exists to avoid.
        $category = $this->selectedCategory();

        if ($category === null) {
            return collect();
        }

        // Scoped to what this category allows — its own allow-list plus
        // every ancestor's, so picking "Clothing" offers Colour, Size and
        // Material, and picking "Clothing > Men > Tops" still does without
        // the attributes being re-scoped at every depth. Same resolution the
        // admin's own "Variation axes" picker uses.
        $allowedAttributeIds = ResolveAllowedAttributes::forCategory($category);

        /** @var Collection<int, AttributeValue> $allValues */
        $allValues = AttributeValue::query()
            ->whereIn('attribute_id', $allowedAttributeIds)
            ->whereHas('attribute', function (Builder $attribute): Builder {
                /** @var Builder<Attribute> $attribute */
                return $attribute->where('is_filterable', true);
            })
            ->with('attribute')
            ->get();

        // A value counts as "offered" if some visible product carries it
        // either way — descriptively, or as a variation axis on one of the
        // product's own variations. Colour and Size only ever reach here
        // through the second path, since is_variation_only forbids the
        // first; Material can reach through either, in principle.
        //
        // skipAttributeId, not skip: 'attributeValueIds' — the value's own
        // attribute is excluded from the filter, but every *other* selected
        // attribute still applies. Reported live: picking Material=Denim
        // left Colour and Size showing their unfiltered, whole-catalogue
        // counts instead of narrowing to Denim's own 3 products, because
        // skip: 'attributeValueIds' dropped every attribute filter at once
        // rather than just the one being evaluated. Grouped by attribute_id
        // first (rather than resolved per value inside the query closure)
        // because a value's own attribute_id is not something a
        // Builder-scoped where() closure can read off the row it is still
        // building — every value sharing one attribute needs the same skip,
        // so the query is built once per attribute rather than once per
        // value.
        $values = $allValues
            ->groupBy('attribute_id')
            ->flatMap(function (Collection $group, int $attributeId): Collection {
                return $group->filter(function (AttributeValue $value) use ($attributeId): bool {
                    return Product::query()
                        ->where(fn (Builder $q) => $q
                            ->whereHas('descriptiveAttributeValues', fn (Builder $v) => $v->whereKey($value->id))
                            ->orWhereHas(
                                'productVariations',
                                fn (Builder $variation) => $variation->whereHas(
                                    'attributeValues',
                                    fn (Builder $v) => $v->whereKey($value->id),
                                ),
                            ))
                        ->tap(fn (Builder $q) => $this->applyFilters($q, skipAttributeId: $attributeId))
                        ->exists();
                });
            });

        // withCount() cannot express "count distinct products reached
        // through either of two separate relations" in one aggregate
        // without a raw subquery per value; counting per value with a plain
        // query keeps the SQL readable at the cost of one extra query per
        // offered value; the values shown are always a small,
        // AttributeValue::query()-bounded set (attribute_values.parent).
        $values->each(function (AttributeValue $value): void {
            /** @var int $attributeId */
            $attributeId = $value->getAttribute('attribute_id');

            $value->setAttribute('products_count', Product::query()
                ->where(fn (Builder $q) => $q
                    ->whereHas('descriptiveAttributeValues', fn (Builder $v) => $v->whereKey($value->id))
                    ->orWhereHas(
                        'productVariations',
                        fn (Builder $variation) => $variation->whereHas(
                            'attributeValues',
                            fn (Builder $v) => $v->whereKey($value->id),
                        ),
                    ))
                ->tap(fn (Builder $q) => $this->applyFilters($q, skipAttributeId: $attributeId))
                ->count());
        });

        // Keyed by attribute name for the sidebar's own grouping. Resolved
        // through a local rather than inline `$value->attribute->name`,
        // which Larastan reads as Model::$name on the BelongsTo's generic.
        $nameOf = static function (AttributeValue $value): string {
            /** @var Attribute $attribute */
            $attribute = $value->attribute;

            return $attribute->name;
        };

        // ->toBase() on each group: Eloquent's own Collection is typed to
        // hold Models, so a Collection *of Collections* is not expressible
        // as one — the outer and inner both become plain Support
        // Collections, which is all the view needs.
        //
        // groupBy() already separates attributes, so ordering the groups
        // among themselves doesn't matter here (ksort below only keeps that
        // order stable); what matters is sort_order *within* each group —
        // Collection::sortBy() only accepts one criterion per call
        // (a bare array of closures is not the multi-column form some other
        // collection methods accept, and silently sorts by neither), so each
        // group is sorted on its own after grouping rather than in one pass.
        return $values
            ->groupBy($nameOf)
            ->toBase()
            ->map(fn (SupportCollection $group): SupportCollection => $group
                ->sortBy(fn (AttributeValue $value): int => (int) $value->sort_order)
                ->values()
                ->toBase())
            ->sortKeys();
    }

    /** @return Collection<int, Brand> */
    #[Computed]
    public function brands(): Collection
    {
        return Brand::query()
            ->withCount(['products' => fn (Builder $q) => $this->applyFilters($q, skip: 'brandId')])
            ->having('products_count', '>', 0)
            ->orderBy('name')
            ->get();
    }

    /**
     * The active filters, as chips the customer can dismiss one at a time.
     *
     * @return list<array{key: string, label: string}>
     */
    #[Computed]
    public function activeFilters(): array
    {
        $chips = [];

        if ($this->search !== '') {
            $chips[] = ['key' => 'search', 'label' => '“'.$this->search.'”'];
        }

        $category = $this->selectedCategory();

        if ($category !== null) {
            $chips[] = ['key' => 'categorySlug', 'label' => $category->name];
        }

        if ($this->safeBrandId() !== null) {
            $name = Brand::query()->whereKey($this->safeBrandId())->value('name');

            if (is_scalar($name)) {
                $chips[] = ['key' => 'brandId', 'label' => (string) $name];
            }
        }

        if ($this->inStockOnly) {
            $chips[] = ['key' => 'inStockOnly', 'label' => 'In stock'];
        }

        if ($this->onSaleOnly) {
            $chips[] = ['key' => 'onSaleOnly', 'label' => 'On sale'];
        }

        if ($this->minPrice !== null || $this->maxPrice !== null) {
            $chips[] = ['key' => 'minPrice', 'label' => $this->priceRangeLabel()];
        }

        if ($this->minRating !== null) {
            $minRating = $this->minRating;

            if (! is_scalar($minRating)) {
                throw new InvalidArgumentException('ProductList::$minRating must be a scalar value.');
            }

            $chips[] = ['key' => 'minRating', 'label' => $minRating.'★ & up'];
        }

        // One chip per selected value rather than one for the whole set:
        // they are AND-ed, so dismissing them individually is how a shopper
        // widens a search by one step. Keyed `attributeValue:N` so
        // clearFilter() can tell which to drop — the only chip key that
        // carries a payload, since every other filter is a single value.
        foreach ($this->selectedAttributeValues() as $value) {
            $valueKey = $value->getKey();

            if (! is_scalar($valueKey)) {
                throw new InvalidArgumentException('AttributeValue::getKey() returned a non-scalar value.');
            }

            $chips[] = [
                'key' => 'attributeValue:'.$valueKey,
                'label' => (string) $value->value,
            ];
        }

        return $chips;
    }

    /**
     * The selected values as models, in the order the sidebar lists them,
     * for the chips. Resolved through `filterableAttributeValueIds()` so a
     * forged or stale id never produces a chip for a filter that is not
     * actually applied.
     *
     * @return Collection<int, AttributeValue>
     */
    private function selectedAttributeValues(): Collection
    {
        $ids = $this->filterableAttributeValueIds();

        if ($ids === []) {
            return new Collection;
        }

        return AttributeValue::query()->whereIn('id', $ids)->orderBy('sort_order')->get();
    }

    private function priceRangeLabel(): string
    {
        $minPrice = is_scalar($this->minPrice) ? $this->minPrice : null;
        $maxPrice = is_scalar($this->maxPrice) ? $this->maxPrice : null;

        return match (true) {
            $minPrice !== null && $maxPrice !== null => "€{$minPrice} – €{$maxPrice}",
            $minPrice !== null => "€{$minPrice}+",
            default => "Up to €{$maxPrice}",
        };
    }

    /**
     * Display price, struck-through original, and the saving — resolved once
     * per card rather than re-derived per template expression.
     *
     * The window rule itself lives in `ResolveProductPrice`, shared with
     * `ResolveVariationPrice`, so a card cannot advertise a sale the cart
     * then refuses to honour. ADR-0014.
     */
    /**
     * The price a card shows — the represented variation's own price if the
     * product has one buyable, the product's own price otherwise (no
     * variation is buyable, or none exists at all — see
     * `ResolveCardVariation`).
     *
     * Was unconditionally `ResolveProductPrice::current($product)`: the
     * product's own `regular_price`/`discount_price`, regardless of which
     * variation the card actually represented. Reported live: a variation
     * override on a discounted sibling never reached the card, and the
     * badge could show a saving no buyable variation actually carried.
     */
    public function price(Product $product): ProductPrice
    {
        $variation = ResolveCardVariation::current($product);

        return $variation === null
            ? ResolveProductPrice::current($product)
            : ResolveVariationPrice::detailed($variation);
    }

    /**
     * Sellable units across a product's available variations.
     *
     * Reserved stock is subtracted: a unit held for someone mid-checkout is
     * not one this customer can buy, and showing it as available is how a
     * catalogue promises stock the cart then refuses.
     */
    public function availableStock(Product $product): int
    {
        $available = 0;

        /** @var ProductVariation $variation */
        foreach ($product->productVariations as $variation) {
            if (! $variation->is_available) {
                continue;
            }

            $inventory = $variation->inventory;

            // Larastan types hasOne as non-null, and every Action that creates
            // a variation creates its stock row in the same transaction — but
            // a row written outside them would not, and a catalogue page is
            // the wrong place to fatal over it.
            if (! $inventory instanceof Inventory) {
                continue;
            }

            $available += max(0, $inventory->current_quantity - $inventory->reserved_quantity);
        }

        return $available;
    }

    public function render(): View
    {
        return view('livewire.catalogue.product-list', [
            'products' => $this->productsQuery(),
        ]);
    }

    /** @return LengthAwarePaginator<int, Product> */
    private function productsQuery(): LengthAwarePaginator
    {
        $approvedOnly = function (Builder $q): Builder {
            /** @var Builder<ProductReview> $q */
            return $q->where('approved', true);
        };

        $query = Product::query()
            // priceHistory: last 40 days only — enough for the Omnibus 30-day
            // prior-price line on a card (ADR-0021), loaded here so the grid
            // does not fan out to one query per product.
            ->with([
                'productImages',
                'brand',
                'productVariations.inventory',
                'priceHistory' => fn ($q) => $q->where('recorded_at', '>=', now()->subDays(40)),
            ])
            ->withAvg(['productReviews as rating_avg' => $approvedOnly], 'rating')
            ->withCount(['productReviews as rating_count' => $approvedOnly]);

        $isDemoSort = $this->sortBy === self::DEMO_SORT_KEY && $this->isDemoModeAvailable();

        if ($isDemoSort) {
            // Exactly the curated 13, nothing else — search/category/brand/
            // price/rating/stock/sale are all skipped entirely rather than
            // narrowing the set further. A presenter clicking through a
            // fixed walkthrough should never have a stray filter left over
            // from browsing quietly drop a case out of the sequence.
            return $query
                ->where('is_available', true)
                ->whereNotNull('demo_case_order')
                ->orderBy('demo_case_order', 'asc')
                ->paginate(self::CASES_COUNT, page: 1);
        }

        $this->applyFilters($query);

        return $query
            ->orderBy($this->safeSortBy(), $this->safeSortDir())
            ->paginate(12);
    }

    /**
     * Whether the current viewer may use the "Demo order" sort at all —
     * checked wherever the option is offered (the dropdown) and wherever it
     * would take effect (here), so the two can never disagree.
     */
    public function isDemoModeAvailable(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    /**
     * The single definition of "what is currently being filtered".
     *
     * Shared by the product query and both facet counts, with `$skip` letting
     * a facet exclude its own dimension. One definition means a filter added
     * here cannot be forgotten in the counts.
     *
     * `$skipAttributeId` narrows one step further, for a facet *value's* own
     * count: Colour's options must still narrow when Material=Denim is
     * picked, but a Colour option's own count must not shrink because of
     * Colour's own selection (checking Black must not make every other
     * colour, including Black itself, look unavailable). `$skip` alone
     * cannot express "apply every attribute's filter except this one
     * attribute" — it is all-or-nothing across the whole dimension.
     *
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query, ?string $skip = null, ?int $skipAttributeId = null): void
    {
        $query->where('is_available', true);

        if ($this->search !== '') {
            // Matches an attribute value's own text ("Linen", "Red") as well
            // as the product's name and blurb — a shopper typing a material
            // or colour they remember has no reason to know whether that
            // fact lives on the product itself or on a variation's own axis,
            // so both pivots are checked, the same "either pivot answers it"
            // rule attributeFacets() and applyFilters()'s own attribute-value
            // matching already use. Deliberately not a single concatenated
            // "searchable text" column or method: that would need building
            // and maintaining a denormalised blob kept in sync on every
            // write, for a `LIKE` that still cannot use an index either way
            // — three explicit `LIKE`s over real columns is what this schema
            // already reads, and is only three lines longer.
            $matchesSearch = function (Builder $value): Builder {
                /** @var Builder<AttributeValue> $value */
                return $value->where('value', 'like', '%'.$this->search.'%');
            };

            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('short_description', 'like', '%'.$this->search.'%')
                ->orWhereHas('descriptiveAttributeValues', $matchesSearch)
                ->orWhereHas('productVariations.attributeValues', $matchesSearch));
        }

        if ($skip !== 'categorySlug') {
            // A slug that matches nothing (stale bookmark, hand-edited URL,
            // deleted category) falls through silently rather than erroring
            // — same behaviour the old id-based lookup already had for an
            // id that did not exist, kept rather than introduced.
            $category = $this->selectedCategory();

            if ($category !== null) {
                $query->whereIn('product_category_id', ResolveCategoryFamily::selfAndDescendantIds($category));
            }
        }

        if ($skip !== 'brandId' && $this->safeBrandId() !== null) {
            $query->where('brand_id', $this->safeBrandId());
        }

        if ($skip !== 'attributeValueIds') {
            // OR *within* one attribute's checked values, AND *across*
            // attributes. Checking Black and White both means "either
            // colour" — the ordinary meaning of two boxes in one facet
            // group — not "both at once", which no single variation can
            // ever be. Colour=Black AND Material=Cotton still narrows,
            // because those two facts can coexist on one product. Reported
            // live: checking two values of one attribute returned zero
            // results before this grouping existed.
            //
            // Each value can be satisfied by either pivot: descriptively on
            // the product itself ("what is this made of"), or as a variation
            // axis on any of the product's own live variations ("what makes
            // this one different") — a Colour or Size value is only ever the
            // second kind, since is_variation_only forbids the first. A
            // customer filtering "Blue" does not care which table answers
            // it, only that some buyable form of this product is blue.
            //
            // The ids are re-checked against real, filterable values here
            // rather than trusted from the URL. A forged or stale id simply
            // is not in the set and narrows nothing, which is the same
            // silent fall-through categorySlug takes above.
            foreach ($this->filterableAttributeValueIdsByAttribute() as $attributeId => $valueIds) {
                if ((int) $attributeId === $skipAttributeId) {
                    continue;
                }

                $query->where(fn (Builder $q) => $q
                    ->whereHas(
                        'descriptiveAttributeValues',
                        fn (Builder $value) => $value->whereIn('attribute_values.id', $valueIds),
                    )
                    ->orWhereHas(
                        'productVariations',
                        fn (Builder $variation) => $variation->whereHas(
                            'attributeValues',
                            fn (Builder $value) => $value->whereIn('attribute_values.id', $valueIds),
                        ),
                    ));
            }
        }

        if ($this->inStockOnly) {
            $query->whereHas('productVariations', function (Builder $variation): Builder {
                /** @var Builder<ProductVariation> $variation */
                return $variation
                    ->where('is_available', true)
                    ->whereHas('inventory', function (Builder $inventory): Builder {
                        /** @var Builder<Inventory> $inventory */
                        return $inventory->whereColumn('current_quantity', '>', 'reserved_quantity');
                    });
            });
        }

        if ($this->onSaleOnly) {
            $now = Carbon::now();

            $query->whereNotNull('discount_price')
                ->where(fn (Builder $q) => $q->whereNull('discount_starts_at')->orWhere('discount_starts_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('discount_ends_at')->orWhere('discount_ends_at', '>=', $now));
        }

        // Against regular_price — the sticker price, not the discount-window
        // effective price ResolveProductPrice resolves. A deliberate scope
        // cut, not an oversight: expressing windowActive() a second time in
        // raw SQL here is exactly the duplicate-implementation risk ADR-0014
        // warns about elsewhere ("a card can advertise a sale price the cart
        // then refuses to honour"). Documented in
        // docs/reference/write-rules/cart.md's catalogue-filters section.
        if ($skip !== 'minPrice' && $this->minPrice !== null) {
            $query->where('regular_price', '>=', $this->minPrice);
        }

        if ($skip !== 'minPrice' && $this->maxPrice !== null) {
            $query->where('regular_price', '<=', $this->maxPrice);
        }

        if ($skip !== 'minRating' && $this->minRating !== null) {
            // A product with no approved reviews is always shown, at any
            // tier — whereDoesntHave covers "no reviews at all"; the scalar
            // subquery covers "has reviews, and their average clears the
            // bar". Neither alone is correct: whereDoesntHave on its own
            // would also admit a product with reviews averaging below the
            // bar (it does "have" some, so the doesntHave branch is false,
            // but nothing then checks the average) — both must be OR'd.
            $query->where(function (Builder $q) {
                $q->whereDoesntHave('productReviews', function (Builder $r): Builder {
                    /** @var Builder<ProductReview> $r */
                    return $r->where('approved', true);
                })
                    ->orWhere(
                        fn (QueryBuilder $sub) => $sub->selectRaw('avg(rating)')
                            ->from('product_reviews')
                            ->whereColumn('product_reviews.product_id', 'products.id')
                            ->where('approved', true),
                        '>=',
                        $this->minRating,
                    );
            });
        }
    }

    /**
     * A clean int-shaped value normalises; anything else (non-numeric, a
     * decimal, a number PHP represents as a float once past its int range)
     * becomes null, same as a well-formed id for a brand that does not
     * exist — both fall through to "no brand filter applied" rather than
     * erroring.
     */
    private function safeBrandId(): ?int
    {
        return is_numeric($this->brandId) && (int) $this->brandId == $this->brandId
            ? (int) $this->brandId
            : null;
    }

    private function safeSortBy(): string
    {
        return array_key_exists($this->sortBy, self::SORTS) ? $this->sortBy : 'created_at';
    }

    /** @return 'asc'|'desc' */
    private function safeSortDir(): string
    {
        return $this->sortDir === 'asc' ? 'asc' : 'desc';
    }
}
