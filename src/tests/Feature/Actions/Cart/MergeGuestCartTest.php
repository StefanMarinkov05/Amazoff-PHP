<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\MergeGuestCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;

/*
 * §11: a guest cart merges into the customer's on login. The collision is the
 * whole difficulty — UNIQUE(cart_id, product_variation_id) forbids two rows
 * for one variation, so a plain move violates it on the second line and the
 * quantities have to be summed instead.
 *
 * cartVariation() and emptyCart() come from tests/Pest.php.
 */

it('moves lines the user cart does not have', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    $variation = cartVariation(stock: 10);
    app(AddToCart::class)->handle($guest, $variation, 3);

    app(MergeGuestCart::class)->handle($guest, $user);

    expect($user->cartItems()->sole()->product_variation_id)->toBe($variation->getKey())
        ->and($user->cartItems()->sole()->quantity)->toBe(3);
});

it('sums quantities where both carts hold the same variation', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    $variation = cartVariation(stock: 10);
    app(AddToCart::class)->handle($guest, $variation, 3);
    app(AddToCart::class)->handle($user, $variation, 2);

    app(MergeGuestCart::class)->handle($guest, $user);

    // Inserting instead would violate the unique key; overwriting would lose
    // whichever cart the customer filled first.
    expect($user->cartItems()->sole()->quantity)->toBe(5)
        ->and(CartItem::count())->toBe(1);
});

it('handles a merge that is partly collision and partly move', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    $shared = cartVariation(stock: 10);
    $guestOnly = cartVariation(stock: 10);
    $userOnly = cartVariation(stock: 10);

    app(AddToCart::class)->handle($guest, $shared, 1);
    app(AddToCart::class)->handle($guest, $guestOnly, 4);
    app(AddToCart::class)->handle($user, $shared, 2);
    app(AddToCart::class)->handle($user, $userOnly, 6);

    app(MergeGuestCart::class)->handle($guest, $user);

    $quantities = $user->cartItems()->pluck('quantity', 'product_variation_id');

    expect($quantities[$shared->getKey()])->toBe(3)
        ->and($quantities[$guestOnly->getKey()])->toBe(4)
        ->and($quantities[$userOnly->getKey()])->toBe(6)
        ->and($user->cartItems()->count())->toBe(3);
});

it('deletes the guest cart once merged', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    app(AddToCart::class)->handle($guest, cartVariation(stock: 10), 1);

    app(MergeGuestCart::class)->handle($guest, $user);

    // An empty cart with a stale session_id serves nothing, and leaving it
    // means the next anonymous request can find it again.
    expect(Cart::whereKey($guest->getKey())->exists())->toBeFalse();
});

it('deletes the guest cart even when it was empty', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());

    app(MergeGuestCart::class)->handle($guest, $user);

    expect(Cart::whereKey($guest->getKey())->exists())->toBeFalse()
        ->and($user->cartItems()->count())->toBe(0);
});

it('leaves no orphan lines behind from the guest cart', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    app(AddToCart::class)->handle($guest, cartVariation(stock: 10), 2);
    app(AddToCart::class)->handle($guest, cartVariation(stock: 10), 1);

    app(MergeGuestCart::class)->handle($guest, $user);

    // cart_items.cart_id cascades on delete, so the guest rows go with the
    // cart — the merge copies rather than moves, and a copy that failed would
    // show up here as a count of 4.
    expect(CartItem::count())->toBe(2)
        ->and(CartItem::where('cart_id', $guest->getKey())->count())->toBe(0);
});

it('is a no-op when both arguments are the same cart', function (): void {
    $user = emptyCart(User::factory()->create());
    $variation = cartVariation(stock: 10);
    app(AddToCart::class)->handle($user, $variation, 3);

    $result = app(MergeGuestCart::class)->handle($user, $user);

    // The guard matters: without it the Action would read the cart's lines,
    // sum each into itself, and then delete the cart it was merging into.
    expect($result->getKey())->toBe($user->getKey())
        ->and(Cart::whereKey($user->getKey())->exists())->toBeTrue()
        ->and($user->cartItems()->sole()->quantity)->toBe(3);
});

it('returns the user cart with its merged lines already loaded', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    app(AddToCart::class)->handle($guest, cartVariation(stock: 10), 2);

    $result = app(MergeGuestCart::class)->handle($guest, $user);

    expect($result->getKey())->toBe($user->getKey())
        ->and($result->cartItems()->count())->toBe(1);
});

it('caps a summed quantity that exceeds available stock', function (): void {
    // Two independently-valid carts (3 + 3, each legal on its own against 4
    // in stock) can sum to more than either cart alone ever held.
    // AddToCart checks available() on every call and would refuse the 4th
    // and 5th unit outright — a blind sum here bypassed that entirely.
    // Fixed to cap, not throw: MergeCartOnAuthentication's own rule is that
    // a failed merge must never fail the login.
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    $variation = cartVariation(stock: 4);
    app(AddToCart::class)->handle($guest, $variation, 3);
    app(AddToCart::class)->handle($user, $variation, 3);

    app(MergeGuestCart::class)->handle($guest, $user);

    expect($user->cartItems()->sole()->quantity)->toBe(4);
});

it('merges a quantity below the product minimum', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    $variation = cartVariation(stock: 10, product: ['min_order_quantity' => 6]);
    app(AddToCart::class)->handle($guest, $variation, 6);
    app(MergeGuestCart::class)->handle($guest, $user);

    // Same reasoning in the other direction: the merge revalidates nothing, so
    // a line that was legal when added stays exactly as it was.
    expect($user->cartItems()->sole()->quantity)->toBe(6);
});

it('drops the line entirely when the only available stock is below the product minimum', function (): void {
    // Capping to available stock alone is not enough: 1 unit left on a
    // product with min_order_quantity 2 is not a legal quantity either —
    // AddToCart would refuse it the same way it refuses insufficient
    // stock. There is no smaller legal quantity to cap down to, so the
    // line is dropped rather than left at a value nothing else in the app
    // would ever accept.
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    $variation = cartVariation(stock: 3, product: ['min_order_quantity' => 2]);
    app(AddToCart::class)->handle($guest, $variation, 3);

    // Stock drops to 1 after the cart line was legally built against 3.
    $variation->inventory->update(['current_quantity' => 1]);

    app(MergeGuestCart::class)->handle($guest, $user);

    expect($user->cartItems()->count())->toBe(0);
});

it('merges lines whose variation has since been soft-deleted', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    $variation = cartVariation(stock: 10);
    app(AddToCart::class)->handle($guest, $variation, 2);
    $variation->delete();

    app(MergeGuestCart::class)->handle($guest, $user);

    // The catalogue is not consulted, so a dead line survives login and the
    // customer sees it on the cart page rather than losing it silently.
    expect($user->cartItems()->sole()->quantity)->toBe(2);
});

it('leaves nothing behind when a line cannot be written', function (): void {
    $guest = emptyCart();
    $user = emptyCart(User::factory()->create());
    app(AddToCart::class)->handle($guest, cartVariation(stock: 10), 2);
    app(AddToCart::class)->handle($guest, cartVariation(stock: 10), 1);

    // Fault injection on the second write, because nothing in the schema can
    // make it fail on its own once the collision is summed rather than
    // inserted. Without the DB::transaction the guest cart would lose the line
    // that did copy and keep the one that did not.
    $writes = 0;
    CartItem::creating(function () use (&$writes): void {
        if (++$writes === 2) {
            throw new RuntimeException('cart line write failed');
        }
    });

    expect(fn () => app(MergeGuestCart::class)->handle($guest, $user))
        ->toThrow(RuntimeException::class);

    expect($user->cartItems()->count())->toBe(0)
        ->and(Cart::whereKey($guest->getKey())->exists())->toBeTrue()
        ->and($guest->cartItems()->count())->toBe(2);
});
