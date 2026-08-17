<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Support\CalculateCartTotals;
use Illuminate\Support\Carbon;

/*
 * Prices are stored gross (CLAUDE.md, BG B2C convention), so VAT is extracted
 * from the total rather than added to it: rate / (100 + rate). That is the
 * arithmetic worth pinning — a net-plus-VAT reading of the same numbers gives
 * 20.00 where this gives 16.66, and both look plausible in isolation.
 *
 * cartVariation() and emptyCart() come from tests/Pest.php.
 */

it('returns zeroes for an empty cart', function (): void {
    expect(CalculateCartTotals::forCart(emptyCart()))
        ->toBe(['subtotal' => '0.00', 'vat' => '0.00', 'total' => '0.00']);
});

it('multiplies price by quantity', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '19.99']), 3);

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('59.97');
});

it('extracts VAT from the gross line total at the standard rate', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle(
        $cart,
        cartVariation(product: ['regular_price' => '100.00', 'vat_rate' => 20.00]),
        1,
    );

    // 100 gross at 20% is 83.33 net plus 16.67 VAT, truncated to 16.66 by
    // bcdiv. Not 20.00 — that would be the answer if 100 were the net price.
    expect(CalculateCartTotals::forCart($cart))
        ->toBe(['subtotal' => '100.00', 'vat' => '16.66', 'total' => '100.00']);
});

it('extracts VAT at the reduced rate', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle(
        $cart,
        cartVariation(product: ['regular_price' => '100.00', 'vat_rate' => 9.00]),
        1,
    );

    expect(CalculateCartTotals::forCart($cart)['vat'])->toBe('8.25');
});

it('mixes VAT rates across lines', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00', 'vat_rate' => 20.00]), 1);
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00', 'vat_rate' => 9.00]), 1);

    // Per line, not per cart — §19 snapshots the rate onto the order item for
    // exactly this reason, and one blended rate for a mixed basket is wrong.
    expect(CalculateCartTotals::forCart($cart))
        ->toBe(['subtotal' => '200.00', 'vat' => '24.91', 'total' => '200.00']);
});

it('reports the total as the gross subtotal', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '49.50']), 2);

    $totals = CalculateCartTotals::forCart($cart);

    // VAT is inside the subtotal, not on top of it, so the two are equal until
    // delivery and coupons arrive (slices 4 and 8).
    expect($totals['total'])->toBe($totals['subtotal'])
        ->and($totals['total'])->toBe('99.00');
});

it('sums several lines', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '19.99']), 2);
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '5.55']), 4);

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('62.18');
});

it('prices a line at the active discount', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(product: [
        'regular_price' => '100.00',
        'discount_price' => '80.00',
        'discount_starts_at' => Carbon::now()->subDay(),
        'discount_ends_at' => Carbon::now()->addDay(),
    ]);
    app(AddToCart::class)->handle($cart, $variation, 2);

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('160.00');
});

it('prices a line at the variation override', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(
        product: ['regular_price' => '100.00'],
        variation: ['price' => '129.90'],
    );
    app(AddToCart::class)->handle($cart, $variation, 1);

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('129.90');
});

it('follows a price change without the cart being touched', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(product: ['regular_price' => '100.00']);
    app(AddToCart::class)->handle($cart, $variation, 1);

    $variation->product->update(['regular_price' => '120.00']);

    // §11's rule, observable: the cart stores no price, so a catalogue change
    // reaches an existing line on the next render. This is the open question in
    // actions-plan.md — the customer currently sees the new price.
    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('120.00');
});

it('follows a discount window opening between renders', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(product: [
        'regular_price' => '100.00',
        'discount_price' => '80.00',
        'discount_starts_at' => Carbon::now()->addHour(),
        'discount_ends_at' => Carbon::now()->addDay(),
    ]);
    app(AddToCart::class)->handle($cart, $variation, 1);

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('100.00');

    Carbon::setTestNow(Carbon::now()->addHours(2));

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('80.00');

    Carbon::setTestNow();
});

it('excludes a line whose variation has been soft-deleted rather than throwing', function (): void {
    $cart = emptyCart();
    $live = cartVariation(product: ['regular_price' => '19.99']);
    $dead = cartVariation(product: ['regular_price' => '100.00']);
    app(AddToCart::class)->handle($cart, $live, 2);
    app(AddToCart::class)->handle($cart, $dead, 1);

    $dead->delete();

    // The stale line is left in the cart (reference/write-rules/cart.md) for
    // RemoveFromCart to clear, but it cannot be priced, so it contributes
    // nothing rather than crashing the total.
    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('39.98')
        ->and($cart->cartItems()->count())->toBe(2);
});

it('excludes a line whose product has been soft-deleted rather than throwing', function (): void {
    $cart = emptyCart();
    $live = cartVariation(product: ['regular_price' => '19.99']);
    $dead = cartVariation(product: ['regular_price' => '100.00']);
    app(AddToCart::class)->handle($cart, $live, 2);
    app(AddToCart::class)->handle($cart, $dead, 1);

    $dead->product->delete();

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('39.98')
        ->and($cart->cartItems()->count())->toBe(2);
});

it('returns strings so the result can be fed to bcmath', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '10.00']), 1);

    expect(CalculateCartTotals::forCart($cart))
        ->each->toBeString();
});

it('keeps a cent that float arithmetic would lose', function (): void {
    $cart = emptyCart();
    // 0.10 + 0.20 in float is 0.30000000000000004, and 1999 lines of 0.10
    // drift visibly. bcadd does not.
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '0.10']), 3);
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '0.20']), 1);

    expect(CalculateCartTotals::forCart($cart)['subtotal'])->toBe('0.50');
});
