<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Actions\Coupon\ApplyCoupon;
use App\Actions\Order\CreateOrder;
use App\Enums\AddressType;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CouponNotApplicableException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\InsufficientStockException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
 * cartVariation() and emptyCart() come from tests/Pest.php.
 *
 * Server-side total recomputation (§28, obligation 3) is structural here
 * rather than a "submit a hostile total" test — CreateOrder's signature has
 * no total input at all, so there is no field to bypass through. What is
 * tested instead is that the stored total matches what the cart's own
 * contents compute to, proving nothing else could have produced it.
 */

/**
 * @return array<string, mixed>
 */
function checkoutCustomer(array $overrides = []): array
{
    return array_merge([
        'email' => fake()->unique()->safeEmail(),
        'phone' => fake()->phoneNumber(),
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'payment_method' => PaymentMethod::CashOnDelivery,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function checkoutAddress(array $overrides = []): array
{
    return array_merge([
        'delivery_type' => DeliveryType::Address,
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'phone' => fake()->phoneNumber(),
        'country' => fake()->countryCode(),
        'city' => fake()->city(),
        'postcode' => fake()->postcode(),
        'street' => fake()->streetName(),
    ], $overrides);
}

it('creates an order with items and both addresses', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10, product: ['regular_price' => '25.00', 'vat_rate' => 20.00]);
    app(AddToCart::class)->handle($cart, $variation, 2);

    $order = app(CreateOrder::class)->handle(
        $cart,
        checkoutCustomer(),
        checkoutAddress(),
        checkoutAddress(),
        null,
    );

    expect($order->subtotal_amount)->toEqualWithDelta(50.00, 0.001)
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->payment_status)->toBe(PaymentStatus::Pending)
        ->and(OrderItem::where('order_id', $order->getKey())->count())->toBe(1)
        ->and(OrderAddress::where('order_id', $order->getKey())->count())->toBe(2);
});

it('recomputes the total from the cart rather than trusting any input', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '19.99', 'vat_rate' => 20.00]), 3);

    $order = app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);

    // 19.99 * 3 = 59.97, no discount, no shipping yet (slice 8).
    expect((string) $order->subtotal_amount)->toBe('59.97')
        ->and((string) $order->total_amount)->toBe('59.97');
});

it('writes one billing and one delivery address, each typed correctly', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $order = app(CreateOrder::class)->handle(
        $cart,
        checkoutCustomer(),
        checkoutAddress(['city' => 'Sofia']),
        checkoutAddress(['city' => 'Plovdiv']),
        null,
    );

    $billing = OrderAddress::where('order_id', $order->getKey())->where('type', AddressType::Billing)->sole();
    $delivery = OrderAddress::where('order_id', $order->getKey())->where('type', AddressType::Delivery)->sole();

    expect($billing->city)->toBe('Sofia')
        ->and($delivery->city)->toBe('Plovdiv');
});

it('snapshots product and variation data onto the order item', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(product: ['name' => 'Widget', 'regular_price' => '10.00', 'vat_rate' => 20.00]);
    app(AddToCart::class)->handle($cart, $variation, 1);

    $order = app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);
    $item = OrderItem::where('order_id', $order->getKey())->sole();

    expect($item->product_name)->toBe('Widget')
        ->and($item->product_sku)->toBe($variation->sku)
        ->and((string) $item->unit_price)->toBe('10.00')
        ->and((string) $item->vat_rate)->toBe('20.00');
});

it('leaves an order item snapshot unchanged when the product changes afterward', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(product: ['name' => 'Original Name', 'regular_price' => '10.00', 'vat_rate' => 20.00]);
    app(AddToCart::class)->handle($cart, $variation, 1);

    $order = app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);
    $item = OrderItem::where('order_id', $order->getKey())->sole();

    $variation->product->update(['name' => 'Renamed', 'vat_rate' => 9.00]);

    expect($item->fresh()->product_name)->toBe('Original Name')
        ->and((string) $item->fresh()->vat_rate)->toBe('20.00');
});

it('refuses an empty cart', function (): void {
    $cart = emptyCart();

    expect(fn () => app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null))
        ->toThrow(EmptyCartException::class);

    expect(Order::count())->toBe(0);
});

it('refuses a cart whose only line points at a soft-deleted variation', function (): void {
    $cart = emptyCart();
    $variation = cartVariation();
    app(AddToCart::class)->handle($cart, $variation, 1);
    $variation->delete();

    expect(fn () => app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null))
        ->toThrow(EmptyCartException::class);
});

it('redeems an applied coupon and applies its discount to the order', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00', 'vat_rate' => 20.00]), 1);
    $coupon = Coupon::factory()->create([
        'code' => 'SAVE10',
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '10.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'total_usage_limit' => null,
        'usage_limit_per_customer' => null,
    ]);
    app(ApplyCoupon::class)->handle($cart, $coupon->code);

    $order = app(CreateOrder::class)->handle($cart->fresh(), checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);

    expect((string) $order->discount_amount)->toBe('10.00')
        ->and((string) $order->total_amount)->toBe('90.00')
        ->and(CouponRedemption::where('order_id', $order->getKey())->count())->toBe(1);
});

it('never writes a per-line discount even when an order-level coupon discount applies', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = Coupon::factory()->create([
        'code' => 'FLAT5',
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '5.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'total_usage_limit' => null,
        'usage_limit_per_customer' => null,
    ]);
    app(ApplyCoupon::class)->handle($cart, $coupon->code);

    $order = app(CreateOrder::class)->handle($cart->fresh(), checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);
    $item = OrderItem::where('order_id', $order->getKey())->sole();

    // Decision 3: the discount is an order-level deduction, never a rewrite
    // of line prices.
    expect((string) $item->discount_amount)->toBe('0.00')
        ->and((string) $order->discount_amount)->toBe('5.00');
});

it('refuses and writes nothing when the coupon became invalid after being applied', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '100.00']), 1);
    $coupon = Coupon::factory()->create([
        'code' => 'GONE',
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '5.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'total_usage_limit' => null,
        'usage_limit_per_customer' => null,
    ]);
    app(ApplyCoupon::class)->handle($cart, $coupon->code);
    $coupon->update(['is_active' => false]);

    expect(fn () => app(CreateOrder::class)->handle($cart->fresh(), checkoutCustomer(), checkoutAddress(), checkoutAddress(), null))
        ->toThrow(CouponNotApplicableException::class);

    // The whole transaction rolled back — no order, no items, no redemption.
    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)
        ->and(CouponRedemption::count())->toBe(0);
});

it('refuses and writes nothing when stock runs out mid-transaction', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 1);
    app(AddToCart::class)->handle($cart, $variation, 1);

    // Another order takes the only unit first.
    Inventory::where('product_variation_id', $variation->getKey())->increment('reserved_quantity', 1);

    expect(fn () => app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null))
        ->toThrow(InsufficientStockException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

it('leaves user_id null for a guest checkout even when the email matches an existing account', function (): void {
    $existingUser = User::factory()->create(['email' => 'shared@example.com']);
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $order = app(CreateOrder::class)->handle(
        $cart,
        checkoutCustomer(['email' => 'shared@example.com']),
        checkoutAddress(),
        checkoutAddress(),
        null,
    );

    expect($order->user_id)->toBeNull()
        ->and($existingUser->orders()->count())->toBe(0);
});

it('sets user_id from the actor for a registered customer', function (): void {
    $user = User::factory()->create();
    $cart = emptyCart($user);
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $order = app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), $user);

    expect($order->user_id)->toBe($user->getKey());
});

it('derives a unique serial_number from the order id', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $order = app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);

    expect($order->serial_number)->toBe(sprintf('ORD-%06d', $order->getKey()));
});

it('reserves stock for every line', function (): void {
    $cart = emptyCart();
    $variation = cartVariation(stock: 10);
    app(AddToCart::class)->handle($cart, $variation, 3);

    app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);

    $inventory = Inventory::where('product_variation_id', $variation->getKey())->sole();

    expect($inventory->reserved_quantity)->toBe(3)
        ->and($inventory->available())->toBe(7)
        ->and($inventory->inventoryMovements()->count())->toBe(1);
});

it('proceeds with the surviving lines when one of several is soft-deleted mid-checkout, silently dropping the rest', function (): void {
    $cart = emptyCart();
    $kept = cartVariation(product: ['regular_price' => '10.00']);
    $dropped = cartVariation(product: ['regular_price' => '25.00']);
    app(AddToCart::class)->handle($cart, $kept, 1);
    app(AddToCart::class)->handle($cart, $dropped, 1);
    $dropped->delete();

    // Same precedent CalculateCartTotals already sets for a rendered page:
    // a stale line is excluded rather than crashing the total. CreateOrder
    // inherits it unchanged, which for a money-moving checkout is worth
    // pinning explicitly rather than assuming from the cart-page behaviour.
    $order = app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);

    expect((string) $order->subtotal_amount)->toBe('10.00')
        ->and(OrderItem::where('order_id', $order->getKey())->count())->toBe(1);
});

it('caps the order discount at the matched subtotal even when the coupon value would exceed it', function (): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(product: ['regular_price' => '20.00']), 1);
    $coupon = Coupon::factory()->create([
        'code' => 'OVERSHOOT',
        'type' => CouponType::Fixed,
        'scope' => CouponScope::EntireOrder,
        'value' => '20.00',
        'max_discount_amount' => null,
        'minimum_order_value' => null,
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
        'total_usage_limit' => null,
        'usage_limit_per_customer' => null,
    ]);
    app(ApplyCoupon::class)->handle($cart, $coupon->code);
    // An admin raises the coupon's value above the cart's own subtotal after
    // it was applied — the same "re-validated at redemption, never trusted
    // from the cart" mechanism that catches an expired or disabled coupon
    // also re-derives the discount from scratch, so the cap still applies.
    $coupon->update(['value' => '500.00']);

    $order = app(CreateOrder::class)->handle($cart->fresh(), checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);

    expect((string) $order->discount_amount)->toBe('20.00')
        ->and((string) $order->total_amount)->toBe('0.00');
});

it('attributes the order to the actor even if that account was soft-deleted moments earlier', function (): void {
    $user = User::factory()->create();
    $cart = emptyCart($user);
    app(AddToCart::class)->handle($cart, cartVariation(), 1);
    $user->delete();

    // Same shape as an order referencing a since-soft-deleted product: the
    // row still exists (soft delete is an UPDATE, not a DELETE), so the FK
    // is satisfied and nothing here needs to know the account is gone.
    $order = app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), $user);

    expect($order->user_id)->toBe($user->getKey());
});

it('surfaces an uncaught QueryException when the actor account no longer exists at all', function (): void {
    // Deliberately pinning a gap, not a guard: unlike a soft delete, a hard
    // delete removes the users row entirely. orders.user_id is nullOnDelete,
    // which governs an EXISTING child row when its parent is removed — it
    // does not let a NEW insert reference an id that is already gone.
    // CreateOrder has no guard for this today; it was not asked for and
    // this test exists to make that explicit rather than silent.
    $user = User::factory()->create();
    $user->forceDelete();

    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    expect(fn () => app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), $user))
        ->toThrow(QueryException::class);
});

it('creates every order at New/Pending regardless of payment method', function (string $method): void {
    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $order = app(CreateOrder::class)->handle(
        $cart,
        checkoutCustomer(['payment_method' => PaymentMethod::from($method)]),
        checkoutAddress(),
        checkoutAddress(),
        null,
    );

    expect($order->status)->toBe(OrderStatus::New)
        ->and($order->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->payment_method)->toBe(PaymentMethod::from($method));
})->with(['stripe', 'cash_on_delivery']);

it('leaves the cart itself and its items in place afterward', function (): void {
    $cart = emptyCart();
    $item = app(AddToCart::class)->handle($cart, cartVariation(), 1);

    app(CreateOrder::class)->handle($cart, checkoutCustomer(), checkoutAddress(), checkoutAddress(), null);

    expect($cart->fresh())->not->toBeNull()
        ->and($item->fresh())->not->toBeNull();
});
