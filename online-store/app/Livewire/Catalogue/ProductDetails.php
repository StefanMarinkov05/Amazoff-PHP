<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Actions\Cart\AddToCart;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Support\ProductPrice;
use App\Support\ResolveCurrentCart;
use App\Support\ResolveProductPrice;
use App\Support\ResolveVariationPrice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One product — §37 criteria 2 and 4. `explanation/storefront-pages.md`.
 *
 * The `@property-read` block is not decoration: Livewire exposes `#[Computed]`
 * through `__get()`, so without it Larastan reads every `$this->variation` as
 * an undefined property, and the obvious fix — calling `$this->variation()` —
 * silently loses the memoisation.
 *
 * @property-read Product $product
 * @property-read ProductCategory $category
 * @property-read Brand|null $brand
 * @property-read Collection<int, ProductVariation> $variations
 * @property-read ProductVariation|null $variation
 * @property-read ProductPrice $price
 * @property-read int $stock
 * @property-read Collection<int, ProductImage> $gallery
 * @property-read Collection<int, ProductReview> $reviews
 * @property-read list<ProductCategory> $breadcrumb
 * @property-read array<int, array{attribute: Attribute, values: list<AttributeValue>}> $attributeGroups
 */
class ProductDetails extends Component
{
    public ?int $productId = null;

    /** Selected values are derived from this; a parallel array desyncs on `?v=`. */
    #[Url(as: 'v')]
    public ?int $variationId = null;

    public int $imageIndex = 0;

    public int $quantity = 1;

    public function mount(Product $product): void
    {
        abort_unless($product->is_available, 404);

        $this->productId = $product->id;
        $this->quantity = max(1, $product->min_order_quantity ?? 1);

        // Not `$this->variation` — reading the computed here memoises null
        // for the request, and the default below would never be seen.
        if ($this->variations->firstWhere('id', $this->variationId) === null) {
            $this->variationId = $this->variations->first()?->getKey();
        }
    }

    #[Computed]
    public function product(): Product
    {
        return Product::query()
            ->with(['brand', 'productCategory.parent', 'productImages', 'productSpecifications'])
            ->findOrFail($this->productId);
    }

    #[Computed]
    public function category(): ProductCategory
    {
        /** @var ProductCategory $category */
        $category = $this->product->productCategory;

        return $category;
    }

    #[Computed]
    public function brand(): ?Brand
    {
        /** @var Brand|null $brand */
        $brand = $this->product->brand;

        return $brand;
    }

    /**
     * @return Collection<int, ProductVariation>
     */
    #[Computed]
    public function variations(): Collection
    {
        return ProductVariation::query()
            ->where('product_id', $this->productId)
            ->where('is_available', true)
            ->with(['attributeValues.attribute', 'inventory', 'images'])
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function variation(): ?ProductVariation
    {
        return $this->variations->firstWhere('id', $this->variationId);
    }

    #[Computed]
    public function price(): ProductPrice
    {
        $variation = $this->variation;

        return $variation === null
            ? ResolveProductPrice::current($this->product)
            : ResolveVariationPrice::detailed($variation);
    }

    #[Computed]
    public function stock(): int
    {
        $inventory = $this->variation?->inventory;

        if (! $inventory instanceof Inventory) {
            return 0;
        }

        return max(0, $inventory->current_quantity - $inventory->reserved_quantity);
    }

    /**
     * Merged, not either/or: narrowing this to the variation's own images
     * empties the thumbnail strip whenever a variation owns one photo.
     * ADR-0013 — `$own` stays unsorted, it is already in pivot order.
     *
     * @return Collection<int, ProductImage>
     */
    #[Computed]
    public function gallery(): Collection
    {
        /** @var Collection<int, ProductImage> $productImages */
        $productImages = $this->product->productImages;

        /** @var Collection<int, ProductImage>|null $own */
        $own = $this->variation?->images;

        if ($own === null || $own->isEmpty()) {
            return $productImages->sortByDesc('is_main')->values();
        }

        /** @var list<int> $ownIds */
        $ownIds = $own->pluck('id')->all();

        $rest = $productImages
            ->reject(static fn (ProductImage $image): bool => in_array($image->id, $ownIds, true))
            ->sortByDesc('is_main')
            ->values();

        /** @var Collection<int, ProductImage> $merged */
        $merged = $own->concat($rest)->values();

        return $merged;
    }

    /**
     * @return Collection<int, ProductReview>
     */
    #[Computed]
    public function reviews(): Collection
    {
        return ProductReview::query()
            ->where('product_id', $this->productId)
            ->where('approved', true)
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * Root first. Walked, not read two levels deep — categories nest to
     * arbitrary depth and a fixed lookup truncates the trail silently.
     *
     * @return list<ProductCategory>
     */
    #[Computed]
    public function breadcrumb(): array
    {
        $trail = [];

        /** @var ProductCategory|null $category */
        $category = $this->product->productCategory;

        while ($category !== null) {
            array_unshift($trail, $category);

            /** @var ProductCategory|null $category */
            $category = $category->parent;
        }

        return $trail;
    }

    /**
     * Built from the variations, not `$product->attributes` — a value no
     * available variation carries must never render as a choice.
     *
     * @return array<int, array{attribute: Attribute, values: list<AttributeValue>}>
     */
    #[Computed]
    public function attributeGroups(): array
    {
        $groups = [];

        foreach ($this->variations as $variation) {
            /** @var AttributeValue $attributeValue */
            foreach ($variation->attributeValues as $attributeValue) {
                /** @var Attribute|null $attribute */
                $attribute = $attributeValue->attribute;

                if ($attribute === null) {
                    continue;
                }

                $attributeId = $attribute->id;

                if (! isset($groups[$attributeId])) {
                    $groups[$attributeId] = [
                        'attribute' => $attribute,
                        'values' => [],
                    ];
                }

                // Outside the guard: that creates the group once, this runs
                // per value. Keyed by id so repeats collapse.
                $groups[$attributeId]['values'][$attributeValue->id] = $attributeValue;
            }
        }

        foreach ($groups as $id => $group) {
            $groups[$id]['values'] = array_values($group['values']);
        }

        return $groups;
    }

    public function valueIsSelected(int $attributeId, int $valueId): bool
    {
        return ($this->selectedValueIds()[$attributeId] ?? null) === $valueId;
    }

    /**
     * Answered against every *other* attribute's current value — holding this
     * attribute's own fixed would mark every alternative unavailable.
     *
     * In memory, not `whereHas`: this runs once per rendered button.
     */
    public function valueIsAvailable(int $attributeId, int $valueId): bool
    {
        $target = $this->selectedValueIds();
        $target[$attributeId] = $valueId;

        return $this->variationCarrying($target) !== null;
    }

    /**
     * Falls back to any variation carrying the value when the exact
     * combination has none, so picking Red from Blue/XL still moves.
     */
    public function selectValue(int $attributeId, int $valueId): void
    {
        $target = $this->selectedValueIds();
        $target[$attributeId] = $valueId;

        $match = $this->variationCarrying($target) ?? $this->variationCarrying([$valueId]);

        if ($match === null) {
            return;
        }

        $this->variationId = $match->getKey();
        $this->imageIndex = 0;

        unset($this->variation, $this->price, $this->stock, $this->gallery);
    }

    public function setImage(int $index): void
    {
        if ($index >= 0 && $index < $this->gallery->count()) {
            $this->imageIndex = $index;
        }
    }

    public function previousImage(): void
    {
        $count = $this->gallery->count();

        if ($count > 0) {
            $this->imageIndex = ($this->imageIndex - 1 + $count) % $count;
        }
    }

    public function nextImage(): void
    {
        $count = $this->gallery->count();

        if ($count > 0) {
            $this->imageIndex = ($this->imageIndex + 1) % $count;
        }
    }

    public function updatedQuantity(): void
    {
        $this->quantity = max(1, $this->quantity);
    }

    /**
     * Do not pre-check stock here: `AddToCart` re-checks it inside a
     * transaction, and `$this->stock` is read outside one and already stale.
     */
    public function addToCart(AddToCart $addToCart): void
    {
        if ($this->variation === null) {
            $this->addError('cart', 'Choose an option first!');

            return;
        }
        try {
            $addToCart->handle(
                ResolveCurrentCart::forVisitor(),
                $this->variation,
                $this->quantity
            );

            $this->dispatch('cart-updated');
            session()->flash('success', 'Added to cart!');
        } catch (
            RemovedFromCatalogueException|
            InvalidCartQuantityException|
            InsufficientStockException $e
        ) {
            $this->addError('cart', $e->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.catalogue.product-details')->title($this->product->name);
    }

    /**
     * The current selection, as attribute id => value id.
     *
     * @return array<int, int>
     */
    private function selectedValueIds(): array
    {
        $selected = [];

        $variation = $this->variation;

        if ($variation === null) {
            return $selected;
        }

        /** @var AttributeValue $value */
        foreach ($variation->attributeValues as $value) {
            $selected[$value->attribute_id] = $value->id;
        }

        return $selected;
    }

    /**
     * The first available variation carrying every one of these value ids.
     *
     * @param  array<int|string, int>  $valueIds
     */
    private function variationCarrying(array $valueIds): ?ProductVariation
    {
        $wanted = array_values($valueIds);

        return $this->variations->first(function (ProductVariation $variation) use ($wanted): bool {
            $has = $variation->attributeValues->pluck('id')->all();

            foreach ($wanted as $id) {
                if (! in_array($id, $has, true)) {
                    return false;
                }
            }

            return true;
        });
    }
}
