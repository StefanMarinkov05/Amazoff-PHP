<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Article;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\ProductPrice;
use App\Support\Resolvers\ResolveCardVariation;
use App\Support\Resolvers\ResolveProductPrice;
use App\Support\Resolvers\ResolveVariationPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * §4's home page — banner, featured/discounted/new/popular products, latest
 * articles, administrator-controlled content.
 *
 * "Administrator-controlled" is `Product::is_featured` — already a real
 * column, already editable in `ProductForm`'s `Toggle::make('is_featured')`,
 * already rendered as an icon in the admin table and infolist — but nothing
 * on the storefront ever read it. This page is that missing consumer, not a
 * new field: an administrator has been able to mark a product featured
 * since the schema was written, with no way for a customer to ever see the
 * effect of doing so.
 *
 * Five independent reads, not one query — each section needs a different
 * `ORDER BY` (curated, discount recency, `created_at`, review volume) that
 * cannot be expressed as a single query's sort. `is_available` is checked
 * on every product section, same as `ProductList`'s own default: this page
 * is a storefront shop window, not an admin listing.
 */
class Home extends Component
{
    private const SECTION_LIMIT = 8;

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function featuredProducts(): Collection
    {
        return Product::query()
            ->where('is_available', true)
            ->where('is_featured', true)
            ->with(['productImages', 'brand'])
            ->latest('id')
            ->limit(self::SECTION_LIMIT)
            ->get();
    }

    /**
     * Same discount-window check `ProductList`'s "On sale" filter uses —
     * not just "has a discount_price set", which would include one whose
     * window has not started yet or already ended.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function discountedProducts(): Collection
    {
        $now = Carbon::now();

        return Product::query()
            ->where('is_available', true)
            ->whereNotNull('discount_price')
            ->where(fn (Builder $q) => $q->whereNull('discount_starts_at')->orWhere('discount_starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('discount_ends_at')->orWhere('discount_ends_at', '>=', $now))
            ->with(['productImages', 'brand'])
            ->latest('id')
            ->limit(self::SECTION_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function newProducts(): Collection
    {
        return Product::query()
            ->where('is_available', true)
            ->with(['productImages', 'brand'])
            ->latest('created_at')
            ->limit(self::SECTION_LIMIT)
            ->get();
    }

    /**
     * "Popular" is approved-review volume, not sales figures — nothing in
     * this schema counts a completed sale per product directly, and review
     * count is the honest proxy already available rather than a new one
     * invented for this page alone.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function popularProducts(): Collection
    {
        $approvedOnly = function (Builder $q): Builder {
            /** @var Builder<ProductReview> $q */
            return $q->where('approved', true);
        };

        return Product::query()
            ->where('is_available', true)
            ->withCount(['productReviews as rating_count' => $approvedOnly])
            ->having('rating_count', '>', 0)
            ->with(['productImages', 'brand'])
            ->orderByDesc('rating_count')
            ->limit(self::SECTION_LIMIT)
            ->get();
    }

    /**
     * Same `visible()` scope `ArticleList` uses — a home page must not leak
     * a draft or a future-dated article any earlier than the journal
     * listing itself would.
     *
     * @return Collection<int, Article>
     */
    #[Computed]
    public function latestArticles(): Collection
    {
        return Article::query()
            ->visible()
            ->with(['author', 'articleCategory'])
            ->latest('published_at')
            ->limit(4)
            ->get();
    }

    /**
     * Display price for a card — the represented variation's own price if
     * the product has one buyable, the product's own price otherwise.
     * Identical to `ProductList::price()`; not extracted into a shared
     * trait yet, since this is the second caller, not the third.
     */
    public function price(Product $product): ProductPrice
    {
        $variation = ResolveCardVariation::current($product);

        return $variation === null
            ? ResolveProductPrice::current($product)
            : ResolveVariationPrice::detailed($variation);
    }

    public function render(): View
    {
        return view('livewire.home');
    }
}
