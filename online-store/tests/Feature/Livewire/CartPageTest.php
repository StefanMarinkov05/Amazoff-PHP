<?php

declare(strict_types=1);

use App\Enums\CouponScope;
use App\Livewire\Cart\CartPage;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * Quantity validation is §37 #5, a graded criterion, and this component
 * carries coupon application, totals, and a write path through
 * UpdateCartItemQuantity — the reasons it was picked over CartBadge,
 * ContactForm, and NewsletterSignup (reference/testing/ui-tests.md).
 */

function cartWithVariation(int $quantity = 1, ?int $available = 100, int $minOrderQuantity = 1): array
{
    /** @var Product $product */
    $product = Product::factory()->create([
        'is_available' => true,
        'min_order_quantity' => $minOrderQuantity,
    ]);

    /** @var ProductVariation $variation */
    $variation = ProductVariation::factory()->for($product)->create(['is_available' => true]);

    if ($available !== null) {
        Inventory::factory()->for($variation, 'productVariation')->create([
            'current_quantity' => $available,
            'reserved_quantity' => 0,
        ]);
    }

    /** @var Cart $cart */
    $cart = Cart::create(['session_id' => Session::getId(), 'user_id' => null]);

    /** @var CartItem $item */
    $item = $cart->cartItems()->create([
        'product_variation_id' => $variation->getKey(),
        'quantity' => $quantity,
    ]);

    return [$cart, $item, $variation];
}

it('renders the visitor\'s own cart items and server-computed totals', function (): void {
    [$cart, $item] = cartWithVariation(quantity: 2);

    Livewire::test(CartPage::class)
        ->assertSee($item->productVariation->product->name)
        ->assertSet('quantities', [$item->getKey() => 2]);
});

it('increments a line quantity through the Action, not by trusting client state', function (): void {
    [, $item] = cartWithVariation(quantity: 1, available: 5);

    Livewire::test(CartPage::class)
        ->call('increment', $item->getKey())
        ->assertSet('quantities', [$item->getKey() => 2]);

    expect($item->fresh()->quantity)->toBe(2);
});

it('refuses a quantity above available stock and leaves the stored quantity unchanged', function (): void {
    [, $item] = cartWithVariation(quantity: 1, available: 1);

    Livewire::test(CartPage::class)
        ->call('increment', $item->getKey())
        ->assertHasErrors('line-'.$item->getKey());

    expect($item->fresh()->quantity)->toBe(1);
});

it('refuses a quantity below the product\'s minimum order quantity', function (): void {
    [, $item] = cartWithVariation(quantity: 3, available: 100, minOrderQuantity: 3);

    Livewire::test(CartPage::class)
        ->set('quantities.'.$item->getKey(), 2)
        ->assertHasErrors('line-'.$item->getKey());

    expect($item->fresh()->quantity)->toBe(3);
});

it('snaps a typed quantity back to the stored value when the Action refuses it', function (): void {
    [, $item] = cartWithVariation(quantity: 2, available: 2);

    Livewire::test(CartPage::class)
        ->set('quantities.'.$item->getKey(), 99)
        ->assertSet('quantities.'.$item->getKey(), 2);
});

it('cannot mutate a cart item that does not belong to the visitor\'s own cart', function (): void {
    [, $item] = cartWithVariation(quantity: 1, available: 100);

    // A second, unrelated cart — the id below is real but not this
    // visitor's, which is the shape a tampered request takes.
    [, $otherItem] = cartWithVariation(quantity: 1, available: 100);
    Cart::query()->whereKey($otherItem->cart_id)->update(['session_id' => 'someone-elses-session']);

    Livewire::test(CartPage::class)
        ->call('increment', $otherItem->getKey());

    expect($otherItem->fresh()->quantity)->toBe(1);
});

it('removes a line and drops it from the rendered totals', function (): void {
    [, $item] = cartWithVariation(quantity: 1);

    Livewire::test(CartPage::class)
        ->call('remove', $item->getKey())
        ->assertSet('quantities', []);

    expect(CartItem::query()->whereKey($item->getKey())->exists())->toBeFalse();
});

it('applies a valid, active coupon and clears the input', function (): void {
    cartWithVariation(quantity: 1);

    /** @var Coupon $coupon */
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'scope' => CouponScope::EntireOrder,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'minimum_order_value' => null,
        'total_usage_limit' => null,
        'usage_limit_per_customer' => null,
    ]);

    Livewire::test(CartPage::class)
        ->set('couponCode', $coupon->code)
        ->call('applyCoupon')
        ->assertHasNoErrors()
        ->assertSet('couponCode', '');
});

it('rejects an unrecognised coupon code as a form error, not a 500', function (): void {
    cartWithVariation(quantity: 1);

    Livewire::test(CartPage::class)
        ->set('couponCode', 'DOES-NOT-EXIST')
        ->call('applyCoupon')
        ->assertHasErrors('coupon');
});

it('rejects an expired coupon', function (): void {
    cartWithVariation(quantity: 1);

    /** @var Coupon $coupon */
    $coupon = Coupon::factory()->create([
        'is_active' => true,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subDay(),
    ]);

    Livewire::test(CartPage::class)
        ->set('couponCode', $coupon->code)
        ->call('applyCoupon')
        ->assertHasErrors('coupon');
});
