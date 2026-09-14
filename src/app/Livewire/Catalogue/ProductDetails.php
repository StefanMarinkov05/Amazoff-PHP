<?php

declare(strict_types=1);

namespace App\Livewire\Catalogue;

use App\Actions\Cart\AddToCart;
use App\Actions\ProductReview\CreateProductReview;
use App\Exceptions\CartLimitExceededException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Exceptions\ReviewNotAllowedException;
use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\ProductPrice;
use App\Support\Resolvers\ResolveCurrentCart;
use App\Support\Resolvers\ResolvePriorPrice;
use App\Support\Resolvers\ResolveProductPrice;
use App\Support\Resolvers\ResolveVariationPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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
 * @property-read bool $canReview
 * @property-read bool $isWishlisted
 * @property-read list<ProductCategory> $breadcrumb
 * @property-read array<int, array{attribute: Attribute, values: list<AttributeValue>}> $attributeGroups
 */
class ProductDetails extends Component
{
    use ThrottlesSubmissions;

    /**
     * `#[Locked]`: set once in `mount()`, never client-set. Without it, a
     * client `$set('productId', <34-digit>)` throws a `TypeError` at
     * hydration — see `SEC-014`.
     */
    #[Locked]
    public ?int $productId = null;

    /**
     * Selected values are derived from this; a parallel array desyncs on
     * `?v=`. Deliberately `mixed`, not `?int` — same incident as
     * `$quantity` below, different property: `?v=` this large
     * (`?v=99999999999999999999999999999999`) decodes to a `float` before
     * Livewire's hydration assigns it, and `?int` refuses a `float`
     * assignment the same way it refused the oversized string for
     * `$quantity` — a raw 500 from a crafted URL, confirmed live before
     * this fix. `mount()` normalises it to a real id or `null` before
     * anything else runs.
     */
    #[Url(as: 'v')]
    public mixed $variationId = null;

    /**
     * `#[Locked]`: only ever set server-side, via `setImage()`/
     * `nextImage()`/`previousImage()` — the blade view only reads it, never
     * `$set`s it. Without the lock, a client `$set('imageIndex', <34-digit>)`
     * throws a `TypeError` at hydration — see `SEC-014`.
     */
    #[Locked]
    public int $imageIndex = 0;

    /**
     * Deliberately `mixed`, not `int` — the star-rating click uses
     * `wire:click="$set('reviewRating', N)"`, legitimately client-set, so it
     * cannot be `#[Locked]` (that throws `CannotUpdateLockedPropertyException`
     * on any client set). Widening avoids the same hydration `TypeError`
     * `$quantity` had; `submitReview()`'s `integer|min:1|max:5` rule still
     * guards what is actually persisted. See `SEC-014`.
     */
    public mixed $reviewRating = 5;

    public string $reviewBody = '';

    public bool $reviewSubmitted = false;

    /**
     * Deliberately untyped, not `int`. `wire:model` sends whatever the
     * client sends — a number field has no server-side ceiling — and
     * Livewire's property hydration assigns the raw value before any of
     * this class's own code runs. A numeric string PHP cannot represent as
     * an `int` (bigger than PHP_INT_MAX, or just very long) throws
     * `TypeError: Cannot assign string to property ... of type int` right
     * there, uncaught, an unhandled 500 from typing a long number into the
     * box. `updatedQuantity()` is where sanitisation actually happens, and
     * it can only run at all if hydration itself does not already throw.
     */
    public mixed $quantity = 1;

    public function mount(Product $product): void
    {
        abort_unless($product->is_available, 404);

        $this->productId = $product->id;
        $this->quantity = max(1, $product->min_order_quantity ?? 1);

        // A clean int-shaped value normalises; anything else (non-numeric,
        // a decimal, a number PHP represents as a float once past its int
        // range) becomes null and falls straight into the "no match" branch
        // below, same as a well-formed id for a variation that does not
        // exist.
        $this->variationId = is_numeric($this->variationId) && (int) $this->variationId == $this->variationId
            ? (int) $this->variationId
            : null;

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
            ->with(['brand', 'productCategory.parent', 'productImages', 'productSpecifications', 'descriptiveAttributeValues.attribute'])
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

    /**
     * The Omnibus prior price (lowest in the 30 days before the reduction) —
     * product-level, so the same figure whichever variation is selected.
     * Null when the product is not on sale or has too little history to draw a
     * compliant number. ADR-0021.
     */
    #[Computed]
    public function priorPrice(): ?string
    {
        return ResolvePriorPrice::forProduct($this->product);
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
     * Whether the signed-in visitor may submit a review right now — a read,
     * so it goes straight to Eloquent rather than through
     * `CreateProductReview`, same reasoning `ADR-0014` gives for every other
     * `#[Computed]` on this page. Mirrors the Action's own two refusal
     * cases (`ReviewNotAllowedException::notPurchased()`/`alreadyReviewed()`)
     * so the form can hide itself instead of only failing on submit — but
     * `submitReview()` still calls the real Action and still handles both
     * exceptions, since a purchase or a review by someone else could land
     * between this render and that click.
     */
    #[Computed]
    public function canReview(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $alreadyReviewed = ProductReview::query()
            ->where('product_id', $this->productId)
            ->where('user_id', $user->getKey())
            ->exists();

        if ($alreadyReviewed) {
            return false;
        }

        return OrderItem::query()
            ->reviewableBy($user)
            ->whereHas('productVariation', function (Builder $query): Builder {
                /** @var Builder<ProductVariation> $query */
                return $query->where('product_id', $this->productId);
            })
            ->exists();
    }

    /**
     * Whether the signed-in visitor has this product on their wishlist —
     * a read, so it goes straight to Eloquent, same reasoning `canReview()`
     * above gives.
     */
    #[Computed]
    public function isWishlisted(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return WishlistItem::query()
            ->where('user_id', $user->getKey())
            ->where('product_id', $this->productId)
            ->exists();
    }

    /**
     * No Action: one INSERT or DELETE on one table with no invariant the
     * schema cannot express beyond `UNIQUE(user_id, product_id)`, which the
     * caught violation below already respects rather than checks first —
     * same idempotency discipline `CreateProductReview` uses. Mirrors
     * `ProductList::toggleWishlist()`; not extracted into a shared trait,
     * since the two callers differ in which id they act on
     * (`$this->productId` here vs. a method parameter there) and the whole
     * method is four lines.
     */
    public function toggleWishlist(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect('/login', navigate: true);

            return;
        }

        $existing = WishlistItem::query()
            ->where('user_id', $user->getKey())
            ->where('product_id', $this->productId)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            try {
                WishlistItem::create(['user_id' => $user->getKey(), 'product_id' => $this->productId]);
            } catch (UniqueConstraintViolationException) {
                // Already wishlisted by a concurrent click from the same
                // user — nothing to do, the row this click wanted already
                // exists.
            }
        }

        unset($this->isWishlisted);
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

    /**
     * The only place `$quantity` is turned back into a real, bounded `int`
     * after Livewire's hydration accepts it as `mixed` — everything else in
     * this class (the template's `+`/`-` buttons, `addToCart()`) can then
     * trust it is already a clean int and never re-validates it.
     *
     * Garbage input (non-numeric, decimal, a number PHP cannot represent
     * cleanly) resets to the product's minimum rather than being clamped
     * partially — there is no sensible "closest valid number" to a string
     * that was never a number. `AddToCart` still re-checks minimum and
     * stock server-side regardless; this only stops a malformed value from
     * ever reaching that call, or from sitting in the input looking valid.
     */
    public function updatedQuantity(): void
    {
        $product = $this->product;
        $minimum = max(1, $product->min_order_quantity);
        $raw = $this->quantity;

        if (! is_numeric($raw) || (int) $raw != $raw || (string) (int) $raw !== trim((string) $raw)) {
            $this->quantity = $minimum;

            return;
        }

        // $this->stock is 0 for a sold-out variation; the Add to cart button
        // is already disabled in that case; the ceiling below floors to at
        // least $minimum rather than clamping to a 0 no valid order can meet.
        $ceiling = max($minimum, $this->stock);

        $this->quantity = (int) min($ceiling, max($minimum, (int) $raw));
    }

    /**
     * Do not pre-check stock here: `AddToCart` re-checks it inside a
     * transaction, and `$this->stock` is read outside one and already stale.
     */
    public function addToCart(AddToCart $addToCart): void
    {
        // Captured into a local rather than re-read below: `variation` is a
        // #[Computed], so every access is a fresh evaluation and the null
        // check above narrows nothing for the call that follows it.
        $variation = $this->variation;

        if ($variation === null) {
            $this->addError('cart', 'Choose an option first!');

            return;
        }

        // updatedQuantity() normalises this to a real int on every change;
        // guard explicitly anyway at the domain boundary rather than trust
        // that every path into this method ran through that hook first —
        // $quantity's declared type is `mixed` (see its own docblock).
        $quantity = $this->quantity;

        if (! is_scalar($quantity)) {
            throw new InvalidArgumentException('ProductDetails::$quantity must be a scalar value.');
        }

        // Keyed on IP, not on the variation: keying on the thing being
        // submitted hands an attacker the full allowance per item, which is
        // not a limit on volume at all (SEC-010). Every add is a write plus
        // a stock read, and nothing bounded how many a script could issue.
        // 60/minute is far above a human clicking through a catalogue and
        // far below what a loop would manage.
        //
        // After the variation guard, so a customer who clicks before
        // choosing an option does not spend their allowance on a misclick.
        $this->throttleSubmission('add-to-cart|'.$this->requestIp(), 'cart', maxAttempts: 60, decaySeconds: 60);

        try {
            $addToCart->handle(
                ResolveCurrentCart::forVisitor(),
                $variation,
                (int) $quantity
            );

            $this->dispatch('cart-updated');
            session()->flash('success', 'Added to cart!');
        } catch (
            RemovedFromCatalogueException|
            InvalidCartQuantityException|
            InsufficientStockException|
            CartLimitExceededException $e
        ) {
            $this->addError('cart', $e->getMessage());
        }
    }

    /**
     * Submits a review through `CreateProductReview`, which is where §24's
     * verified-purchase rule and the one-review-per-customer constraint are
     * actually enforced — `canReview()` above only decides whether to show
     * the form, never whether to accept what it submits.
     */
    public function submitReview(CreateProductReview $createProductReview): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $validated = $this->validate([
            'reviewRating' => 'required|integer|min:1|max:5',
            'reviewBody' => 'required|string|min:10|max:2000',
        ]);

        try {
            $createProductReview->handle(
                $this->product,
                $user,
                (int) $validated['reviewRating'],
                $validated['reviewBody'],
            );

            unset($this->reviews, $this->canReview);
            $this->reset(['reviewRating', 'reviewBody']);
            $this->reviewRating = 5;
            $this->reviewSubmitted = true;
        } catch (ReviewNotAllowedException $e) {
            $this->addError('review', $e->getMessage());
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
