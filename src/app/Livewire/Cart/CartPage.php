<?php

declare(strict_types=1);

namespace App\Livewire\Cart;

use App\Actions\Cart\RemoveFromCart;
use App\Actions\Cart\TouchCartExpiry;
use App\Actions\Cart\UpdateCartItemQuantity;
use App\Actions\Coupon\ApplyCoupon;
use App\Actions\Coupon\RemoveCoupon;
use App\Exceptions\CartLimitExceededException;
use App\Exceptions\CouponNotApplicableException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidCartQuantityException;
use App\Exceptions\RemovedFromCatalogueException;
use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Support\CalculateCartTotals;
use App\Support\CalculateCouponDiscount;
use App\Support\CouponDiscountLine;
use App\Support\Money;
use App\Support\Resolvers\ResolveCurrentCart;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The basket — §37 criteria 4 and 5.
 *
 * @property-read Cart $cart
 * @property-read Collection<int, CartItem> $items
 * @property-read array{subtotal: string, vat: string, total: string} $totals
 * @property-read array{discount: string, payable: string} $discount
 */
class CartPage extends Component
{
    use ThrottlesSubmissions;

    public string $couponCode = '';

    /**
     * Typed quantities, keyed by cart item id.
     *
     * Mirrors the database rather than replacing it: the row's own
     * `quantity` is still what renders the line total, and this only holds
     * what is in the box while it is being edited. Anything the Action
     * refuses is written back from the row, so the field snaps to the truth
     * instead of showing a number that was never saved.
     *
     * @var array<int, int|string>
     */
    public array $quantities = [];

    public function mount(): void
    {
        $this->syncQuantities();
    }

    #[Computed]
    public function cart(): Cart
    {
        return ResolveCurrentCart::forVisitor();
    }

    /** @return Collection<int, CartItem> */
    #[Computed]
    public function items(): Collection
    {
        /** @var Collection<int, CartItem> $items */
        $items = $this->cart->cartItems()
            ->with([
                'productVariation.product',
                'productVariation.inventory',
                'productVariation.images',
                'productVariation.attributeValues.attribute',
            ])
            ->orderBy('id')
            ->get();

        return $items;
    }

    /**
     * Server-side, always — §37 criterion 8. Never summed in the template.
     *
     * `total` equals `subtotal` on purpose: prices are stored gross, so
     * `CalculateCartTotals` *extracts* VAT from each line rather than adding
     * it on top. `vat` is the "of which" figure, not a surcharge.
     *
     * @return array{subtotal: string, vat: string, total: string}
     */
    #[Computed]
    public function totals(): array
    {
        return CalculateCartTotals::forCart($this->cart);
    }

    /**
     * What the applied coupon takes off, and what the customer actually pays.
     *
     * Separate from `totals()` because `CalculateCartTotals` deliberately
     * knows nothing about coupons — it is also the input `ApplyCoupon` and
     * `CreateOrder` price against, and folding a discount into its subtotal
     * would change what those mean.
     *
     * Recomputed on every render rather than stored: a coupon that was valid
     * when applied can stop being so when a line is removed and the basket
     * drops below its minimum. `CalculateCouponDiscount` throws in that case,
     * and the honest answer is to show no discount rather than a stale one.
     * `RedeemCoupon` at checkout is what finally decides.
     *
     * @return array{discount: string, payable: string, vat: string}
     */
    #[Computed]
    public function discount(): array
    {
        $totals = $this->totals;
        $none = ['discount' => '0.00', 'payable' => $totals['total'], 'vat' => $totals['vat']];

        $coupon = $this->cart->coupon;

        if (! $coupon instanceof Coupon) {
            return $none;
        }

        try {
            $result = CalculateCouponDiscount::forLines(
                $coupon,
                CouponDiscountLine::collectionFromCartItems($this->items),
                $totals['subtotal'],
            );
        } catch (CouponNotApplicableException) {
            return $none;
        }

        return [
            'discount' => $result['discount'],
            'payable' => (string) Money::of($totals['total'])->subtract(Money::of($result['discount'])),
            'vat' => $result['vat'],
        ];
    }

    public function increment(int $itemId, UpdateCartItemQuantity $update, TouchCartExpiry $touch): void
    {
        $this->changeQuantity($itemId, 1, $update, $touch);
    }

    public function decrement(int $itemId, UpdateCartItemQuantity $update, TouchCartExpiry $touch): void
    {
        $this->changeQuantity($itemId, -1, $update, $touch);
    }

    /**
     * A quantity typed into the box.
     *
     * Livewire calls this with the array key as `$key`, so one hook serves
     * every line. Actions are resolved through `app()` rather than injected:
     * lifecycle hooks are called by Livewire itself and do not go through the
     * container's argument resolution the way an action method does.
     */
    public function updatedQuantities(mixed $value, string $key): void
    {
        $itemId = (int) $key;
        $item = $this->ownedItem($itemId);

        if ($item === null) {
            return;
        }

        // An empty box mid-typing is not a request to do anything, and
        // `(int) ''` is 0, which the Action would refuse. Wait for a number.
        if (! is_numeric($value)) {
            $this->quantities[$itemId] = $item->quantity;

            return;
        }

        $wanted = (int) $value;

        if ($wanted === $item->quantity) {
            return;
        }

        if ($wanted < 1) {
            $this->addError('line-'.$itemId, 'Quantity must be at least 1. Use Remove to take the item out.');
            $this->quantities[$itemId] = $item->quantity;

            return;
        }

        try {
            app(UpdateCartItemQuantity::class)->handle($item, $wanted);
        } catch (RemovedFromCatalogueException|InvalidCartQuantityException|InsufficientStockException|CartLimitExceededException $e) {
            $this->addError('line-'.$itemId, $e->getMessage());
            $this->quantities[$itemId] = $item->quantity;

            return;
        }

        app(TouchCartExpiry::class)->handle($this->cart);

        $this->refreshCart();
    }

    public function remove(int $itemId, RemoveFromCart $removeFromCart, TouchCartExpiry $touch): void
    {
        $item = $this->ownedItem($itemId);

        if ($item === null) {
            return;
        }

        $removeFromCart->handle($item);
        $touch->handle($this->cart);

        $this->refreshCart();
    }

    public function applyCoupon(ApplyCoupon $applyCoupon): void
    {
        $code = trim($this->couponCode);

        if ($code === '') {
            return;
        }

        // Keyed on IP rather than on the code: keying on the submitted
        // value would give a guesser the full allowance *per code*, which
        // is the opposite of a limit (SEC-010). Coupon codes are guessable
        // by construction and `RedeemCoupon` takes a `coupons` row lock at
        // checkout, so an unthrottled loop here is both an enumeration
        // oracle and a lock-contention lever.
        //
        // Before the unknown-code check, deliberately: that check is the
        // cheap branch a guesser hits on every wrong attempt, so throttling
        // after it would leave the enumeration path unlimited.
        $this->throttleSubmission('apply-coupon|'.$this->requestIp(), 'coupon', maxAttempts: 20, decaySeconds: 60);

        // `ApplyCoupon` resolves the code with `firstOrFail`, and an unknown
        // code is a typo rather than a refusal — checked here so it reads as a
        // form error instead of surfacing as a 500.
        if (! Coupon::query()->where('code', $code)->exists()) {
            $this->addError('coupon', 'That code was not recognised.');

            return;
        }

        try {
            $applyCoupon->handle($this->cart, $code);
        } catch (CouponNotApplicableException $e) {
            $this->addError('coupon', $e->getMessage());

            return;
        }

        $this->couponCode = '';
        $this->refreshCart();
    }

    public function removeCoupon(RemoveCoupon $removeCoupon): void
    {
        $removeCoupon->handle($this->cart);
        $this->refreshCart();
    }

    public function render(): View
    {
        return view('livewire.cart.cart-page')->title('Your basket');
    }

    private function changeQuantity(int $itemId, int $delta, UpdateCartItemQuantity $update, TouchCartExpiry $touch): void
    {
        $item = $this->ownedItem($itemId);

        if ($item === null) {
            return;
        }

        try {
            $update->handle($item, $item->quantity + $delta);
        } catch (RemovedFromCatalogueException|InvalidCartQuantityException|InsufficientStockException|CartLimitExceededException $e) {
            $this->addError('line-'.$itemId, $e->getMessage());

            return;
        }

        $touch->handle($this->cart);

        $this->refreshCart();
    }

    /**
     * The gate on every id arriving from the browser: an item resolves only if
     * it is in this visitor's own cart, so a tampered id touches nothing.
     */
    private function ownedItem(int $itemId): ?CartItem
    {
        return $this->items->firstWhere('id', $itemId);
    }

    private function refreshCart(): void
    {
        unset($this->cart, $this->items, $this->totals, $this->discount);

        // After the computed cache is dropped, not before — otherwise the
        // boxes are re-seeded from the quantities that were just replaced.
        $this->syncQuantities();

        $this->dispatch('cart-updated');
    }

    private function syncQuantities(): void
    {
        /** @var array<int, int> $byId */
        $byId = $this->items->pluck('quantity', 'id')->all();

        $this->quantities = $byId;
    }
}
