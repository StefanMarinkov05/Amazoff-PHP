<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Models\Brand;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Models\User;
use App\Support\ProductPrice;
use App\Support\ResolveCategoryFamily;
use App\Support\ResolveProductPrice;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
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

    #[Url]
    public string $sortBy = 'created_at';

    #[Url]
    public string $sortDir = 'desc';

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
        'minPrice', 'maxPrice', 'minRating',
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

        if ($this->categorySlug !== null) {
            $name = ProductCategory::query()->where('slug', $this->categorySlug)->value('name');
            if ($name !== null) {
                $chips[] = ['key' => 'categorySlug', 'label' => $name];
            }
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

        return $chips;
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
    public function price(Product $product): ProductPrice
    {
        return ResolveProductPrice::current($product);
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

        if ($skip !== 'categorySlug' && $this->categorySlug !== null) {
            // A slug that matches nothing (stale bookmark, hand-edited URL,
            // deleted category) falls through silently rather than erroring
            // — same behaviour the old id-based lookup already had for an
            // id that did not exist, kept rather than introduced.
            $category = ProductCategory::query()->where('slug', $this->categorySlug)->first();

            if ($category !== null) {
                $query->whereIn('product_category_id', ResolveCategoryFamily::selfAndDescendantIds($category));
            }
        }

        if ($skip !== 'brandId' && $this->brandId !== null) {
            $query->where('brand_id', $this->brandId);
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
