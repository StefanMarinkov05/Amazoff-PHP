<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\User;
use App\Support\ProductPrice;
use App\Support\ResolveAllowedAttributes;
use App\Support\ResolveCardVariation;
use App\Support\ResolveCategoryFamily;
use App\Support\ResolveProductPrice;
use App\Support\ResolveVariationPrice;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\View\View;
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

    #[Url]
    public ?int $brandId = null;

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
     * `mixed` and sanitised in `updatedAttributeValueIds()`, for the same
     * reason `$minRating` and `$minPrice` are: `#[Url]` hydration assigns
     * the raw request value before any validation runs, so a strictly typed
     * property throws on anything it cannot represent. The list is
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
     * Per-attribute staging for the facet `<select multiple>` elements —
     * attribute id (string: Livewire/Alpine array keys are always strings)
     * => the values checked in that one dropdown. Not `#[Url]`-bound itself
     * and not read by `applyFilters()`; `$attributeValueIds` stays the
     * single source of truth the query, the chips, and the URL all read.
     *
     * This exists because Livewire has no way to bind several independent
     * `<select multiple>` elements to one shared flat array — each would
     * overwrite the others' picks on its own change event, since a
     * `wire:model` binding fully owns the property it targets rather than
     * merging into it. One dropdown per attribute, each targeting its own
     * key here, sidesteps that; `updated()` below folds every change back
     * into `$attributeValueIds`.
     *
     * @var array<string, list<int>>
     */
    public array $facetSelections = [];

    /**
     * Seeds {@see $facetSelections} from `$attributeValueIds` so a shared
     * link or a browser back/forward restores each dropdown's own
     * selection, not just the flat list the query reads.
     */
    public function mount(): void
    {
        $this->facetSelections = collect($this->filterableAttributeValueIdsByAttribute())
            ->mapWithKeys(fn (array $ids, int|string $attributeId): array => [(string) $attributeId => $ids])
            ->all();
    }

    public function updated(string $property): void
    {
        // A facet dropdown's own change lands as "facetSelections.5", never
        // as the bare property name — Livewire's dot-path for a nested
        // array key. Re-flattening on every such change, rather than
        // merging just the one key, keeps this correct even if a stale
        // selection referenced an attribute no longer in $facetSelections.
        if (str_starts_with($property, 'facetSelections.')) {
            $this->attributeValueIds = collect($this->facetSelections)->flatten()->all();
        }

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
        $this->facetSelections = [];
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

            $this->attributeValueIds = collect((array) $this->attributeValueIds)
                ->map(fn (mixed $id): int => (int) $id)
                ->reject(fn (int $id): bool => $id === $target)
                ->values()
                ->all();

            // Keeps each facet dropdown's own displayed selection in sync
            // with the chip that was just dismissed — otherwise the select
            // would still show the option checked after its chip vanished.
            $this->facetSelections = collect($this->facetSelections)
                ->map(fn (array $ids): array => array_values(array_diff($ids, [$target])))
                ->all();

            $this->resetPage();

            return;
        }

        if (in_array($filter, self::FILTER_KEYS, true)) {
            $this->reset($filter);

            if ($filter === 'attributeValueIds') {
                $this->facetSelections = [];
            }

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
        $this->attributeValueIds = collect((array) $this->attributeValueIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
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
        $countsByRawCategoryId = Product::query()
            ->tap(fn (Builder $q) => $this->applyFilters($q, skip: 'categorySlug'))
            ->selectRaw('product_category_id, count(*) as total')
            ->groupBy('product_category_id')
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
        return $this->filterableAttributeValueIdsByAttribute()
            ->flatten()
            ->all();
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
        $selected = array_map(intval(...), (array) $this->attributeValueIds);

        if ($selected === []) {
            return collect();
        }

        return AttributeValue::query()
            ->whereIn('id', $selected)
            ->whereHas('attribute', fn (Builder $attribute) => $attribute->where('is_filterable', true))
            ->get(['id', 'attribute_id'])
            ->groupBy('attribute_id')
            ->map(fn (Collection $group): array => $group->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
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

        // A value counts as "offered" if some visible product carries it
        // either way — descriptively, or as a variation axis on one of the
        // product's own variations. Colour and Size only ever reach here
        // through the second path, since is_variation_only forbids the
        // first; Material can reach through either, in principle.
        $matchesEitherPivot = function (Builder $value) {
            $value->where(fn (Builder $q) => $q
                ->whereHas(
                    'products',
                    // @phpstan-ignore argument.type
                    fn (Builder $product) => $this->applyFilters($product, skip: 'attributeValueIds'),
                )
                ->orWhereHas(
                    'productVariations.product',
                    // @phpstan-ignore argument.type
                    fn (Builder $product) => $this->applyFilters($product, skip: 'attributeValueIds'),
                ));
        };

        /** @var Collection<int, AttributeValue> $values */
        $values = AttributeValue::query()
            ->whereIn('attribute_id', $allowedAttributeIds)
            ->whereHas('attribute', fn (Builder $attribute) => $attribute->where('is_filterable', true))
            ->where($matchesEitherPivot)
            ->with('attribute')
            ->get()
            // withCount() cannot express "count distinct products reached
            // through either of two separate relations" in one aggregate
            // without a raw subquery per value; counting per value with a
            // plain query keeps the SQL readable at the cost of one extra
            // query per offered value; the values shown are always a small,
            // AttributeValue::query()-bounded set (attribute_values.parent).
            ->each(function (AttributeValue $value): void {
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
                    ->tap(fn (Builder $q) => $this->applyFilters($q, skip: 'attributeValueIds'))
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
        return $values
            ->sortBy([
                fn (AttributeValue $value): string => $nameOf($value),
                fn (AttributeValue $value): int => (int) $value->sort_order,
            ])
            ->groupBy($nameOf)
            ->toBase()
            ->map(fn (Collection $group): SupportCollection => $group->toBase());
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

        if ($this->brandId !== null) {
            $name = Brand::query()->whereKey($this->brandId)->value('name');
            if ($name !== null) {
                $chips[] = ['key' => 'brandId', 'label' => $name];
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
            $chips[] = ['key' => 'minRating', 'label' => $this->minRating.'★ & up'];
        }

        // One chip per selected value rather than one for the whole set:
        // they are AND-ed, so dismissing them individually is how a shopper
        // widens a search by one step. Keyed `attributeValue:N` so
        // clearFilter() can tell which to drop — the only chip key that
        // carries a payload, since every other filter is a single value.
        foreach ($this->selectedAttributeValues() as $value) {
            $chips[] = [
                'key' => 'attributeValue:'.$value->getKey(),
                'label' => $value->value,
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
        return match (true) {
            $this->minPrice !== null && $this->maxPrice !== null => "€{$this->minPrice} – €{$this->maxPrice}",
            $this->minPrice !== null => "€{$this->minPrice}+",
            default => "Up to €{$this->maxPrice}",
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
        $query = Product::query()
            ->with(['productImages', 'brand', 'productVariations.inventory'])
            ->withAvg(['productReviews as rating_avg' => fn (Builder $q) => $q->where('approved', true)], 'rating')
            ->withCount(['productReviews as rating_count' => fn (Builder $q) => $q->where('approved', true)]);

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
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query, ?string $skip = null): void
    {
        $query->where('is_available', true);

        if ($this->search !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('short_description', 'like', '%'.$this->search.'%'));
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

        if ($skip !== 'brandId' && $this->brandId !== null) {
            $query->where('brand_id', $this->brandId);
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
            foreach ($this->filterableAttributeValueIdsByAttribute() as $valueIds) {
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
            $query->whereHas('productVariations', fn (Builder $variation) => $variation
                ->where('is_available', true)
                ->whereHas('inventory', fn (Builder $inventory) => $inventory
                    ->whereColumn('current_quantity', '>', 'reserved_quantity')));
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
                // Eloquent's where(Closure, operator, value) delegates to the
                // underlying Query\Builder, which calls the closure with a
                // fresh Query\Builder of its own for the subquery — not the
                // Eloquent Builder every other closure in this file receives.
                // Larastan's Eloquent stubs only model orWhere(Closure):
                // Builder — the scalar-subquery-comparison overload used
                // below, orWhere(Closure, operator, value), is real (traced
                // against Illuminate\Database\Query\Builder::where and
                // ::createSub directly) but outside what the stubs cover.
                $q->whereDoesntHave('productReviews', fn (Builder $r) => $r->where('approved', true))
                    ->orWhere(
                        // @phpstan-ignore argument.type
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

    private function safeSortBy(): string
    {
        return array_key_exists($this->sortBy, self::SORTS) ? $this->sortBy : 'created_at';
    }

    private function safeSortDir(): string
    {
        return $this->sortDir === 'asc' ? 'asc' : 'desc';
    }
}
