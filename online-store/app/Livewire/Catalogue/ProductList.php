<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Models\Brand;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $categoryId = null;

    #[Url]
    public ?int $brandId = null;

    #[Url]
    public bool $inStockOnly = false;

    #[Url]
    public bool $onSaleOnly = false;

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
        if (! array_key_exists($column, self::SORTS)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = $column === 'name' ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'categoryId', 'brandId', 'inStockOnly', 'onSaleOnly']);
        $this->resetPage();
    }

    public function clearFilter(string $filter): void
    {
        if (in_array($filter, ['search', 'categoryId', 'brandId', 'inStockOnly', 'onSaleOnly'], true)) {
            $this->reset($filter);
            $this->resetPage();
        }
    }

    /**
     * Categories with a live count, computed against every filter *except*
     * the category itself — picking one should never make the others read
     * zero. Research consensus: a facet whose count is stale is worse than
     * no count at all, because it promises results that are not there.
     *
     * @return Collection<int, ProductCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ProductCategory::query()
            ->withCount(['products' => fn (Builder $q) => $this->applyFilters($q, skip: 'categoryId')])
            ->having('products_count', '>', 0)
            ->orderBy('name')
            ->get();
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

        if ($this->categoryId !== null) {
            $name = ProductCategory::query()->whereKey($this->categoryId)->value('name');
            if ($name !== null) {
                $chips[] = ['key' => 'categoryId', 'label' => $name];
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

        return $chips;
    }

    /** Whether a product's discount price should be shown at all. */
    public function discountIsActive(Product $product): bool
    {
        if ($product->discount_price === null) {
            return false;
        }

        $now = Carbon::now();

        if ($product->discount_starts_at !== null && $now->lt($product->discount_starts_at)) {
            return false;
        }

        return ! ($product->discount_ends_at !== null && $now->gt($product->discount_ends_at));
    }

    /** Whole-percent saving, for the badge. */
    public function discountPercent(Product $product): int
    {
        $regular = (float) $product->regular_price;

        if ($regular <= 0.0 || ! $this->discountIsActive($product)) {
            return 0;
        }

        return (int) round((1 - ((float) $product->discount_price / $regular)) * 100);
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

        $this->applyFilters($query);

        return $query
            ->orderBy($this->safeSortBy(), $this->safeSortDir())
            ->paginate(12);
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

        if ($skip !== 'categoryId' && $this->categoryId !== null) {
            $query->where('product_category_id', $this->categoryId);
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
