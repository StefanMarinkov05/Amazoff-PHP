<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
 * the browser's back button works. That is the difference between a filter
 * and a filter a customer can actually use.
 */
class ProductList extends Component
{
    use WithPagination;

    /**
     * Sortable columns, allow-listed by design.
     *
     * `$sortBy` arrives from the query string, so without this
     * `/catalogue?sortBy=nonsense` reaches `orderBy()` and 500s on an unknown
     * column. Not an injection — Laravel quotes the identifier — but a crash
     * any visitor or crawler can trigger by editing the URL.
     */
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
        $this->reset(['search', 'categoryId', 'brandId']);
        $this->resetPage();
    }

    #[Computed]
    public function categories(): Collection
    {
        return ProductCategory::query()->orderBy('name')->get();
    }

    #[Computed]
    public function brands(): Collection
    {
        return Brand::query()->orderBy('name')->get();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->categoryId !== null || $this->brandId !== null;
    }

    /**
     * Whether a product's discount price should be shown at all.
     *
     * Duplicates the window half of `ResolveVariationPrice::windowActive()`,
     * which is private and takes a variation. Worth collapsing both into a
     * shared `ResolveProductPrice` support class rather than leaving §11's
     * price rule expressed twice — noted, not done here.
     */
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

    public function render(): View
    {
        return view('livewire.catalogue.product-list', [
            'products' => $this->productsQuery(),
        ]);
    }

    private function productsQuery(): LengthAwarePaginator
    {
        return Product::query()
            ->where('is_available', true)
            ->with(['productImages', 'brand'])
            ->when($this->search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('short_description', 'like', '%'.$this->search.'%')
            ))
            ->when($this->categoryId, fn ($query) => $query->where('product_category_id', $this->categoryId))
            ->when($this->brandId, fn ($query) => $query->where('brand_id', $this->brandId))
            ->orderBy($this->safeSortBy(), $this->safeSortDir())
            ->paginate(12);
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
