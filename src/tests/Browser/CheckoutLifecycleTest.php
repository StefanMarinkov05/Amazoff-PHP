<?php

declare(strict_types=1);

use App\Actions\Order\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;

/*
 * The system-simulation E2E — one guest order walked through every real page
 * and every real write, start to finish, in one browser session:
 *
 *   catalogue → product → add to cart → cart → checkout → place (COD) →
 *   confirmation → staff move it to Shipped → guest tracks it.
 *
 * No side doors (see website-testing/system-simulation-testing.md): the cart
 * is built by clicking the real Livewire button, the order is placed by
 * submitting the real checkout form, and the status moves through
 * `TransitionOrderStatus` — the one writer of `orders.status` (CLAUDE.md).
 * The one thing not driven by a browser click is the four status hops: the
 * Filament path is four stacked confirmation modals, and a driver that
 * awaits a modal call never returns (phase-4-admin-panel-clickthrough.md).
 * The modal path is phase-4's job; the *lifecycle* is this test's, so the
 * hops call the same Action the modal would, with a real staff actor and its
 * real policy + matrix guards. A browser assertion at the end confirms staff
 * see the finished order in the panel.
 *
 * Catalogue rows are the documented "no real creation event" exception and
 * are built with the factory (company inventory). The courier is the
 * deterministic `FakeCourierGateway` — `swapFakeCourier()` — so the delivery
 * quote does not depend on Econt's demo host being up.
 */

it('walks a guest purchase from the catalogue to a shipped-order tracking page', function (): void {
    swapFakeCourier();

    $variation = cartVariation(stock: 5, product: [
        'name' => 'Aeropress Go Travel Coffee Press',
        'regular_price' => '39.90',
        'min_order_quantity' => 1,
    ]);
    $product = $variation->product;

    // ── 1–5. Guest: catalogue → product → cart → checkout → place ──────────
    $page = visit('/catalogue');

    $page->assertSee($product->name)
        ->navigate("/products/{$product->slug}")
        ->assertSee($product->name)
        ->click('Add to cart')
        ->assertSee('Added to cart')
        ->navigate('/cart')
        ->assertSee($product->name)
        ->click('Checkout')
        ->assertPathIs('/checkout')
        ->fill('first_name', 'Bilyana')
        ->fill('last_name', 'Ilieva')
        ->fill('email', 'bilyana.lifecycle@example.test')
        ->fill('phone', '+359 88 555 0134')
        ->fill('city', 'Sofia')
        ->fill('postcode', '1000')
        ->fill('street', 'bul. Vitosha 12')
        ->click('Econt')                                     // carrier radio (label)
        ->click('Cash on delivery')                          // payment radio (label)
        ->click('Billing address is the same as delivery')   // checkbox (label)
        ->click('Order with obligation to pay')
        ->assertPathBeginsWith('/checkout/confirmation');

    // ── The real order, asserted from the database ───────────────────────
    $order = Order::query()->where('email', 'bilyana.lifecycle@example.test')->sole();

    expect($order->status)->toBe(OrderStatus::New)
        ->and($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->user_id)->toBeNull()                   // placed as a guest
        ->and($order->orderItems)->toHaveCount(1)
        ->and($order->orderItems->first()->product_variation_id)->toBe($variation->getKey())
        ->and($order->orderItems->first()->quantity)->toBe(1);

    $page->assertSee($order->serial_number);

    // ── 6. Staff move the order to Shipped, through the real Action ───────
    $staff = User::factory()->create();
    $staff->assignRole('administrator');

    foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForShipment, OrderStatus::Shipped] as $target) {
        app(TransitionOrderStatus::class)->handle($order->refresh(), $target, $staff, 'lifecycle E2E');
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Shipped);

    // ── 7. The guest tracks the order — serial + email, no session ───────
    $page->navigate('/orders/track')
        ->fill('serial_number', $order->serial_number)
        ->fill('track_email', 'bilyana.lifecycle@example.test')
        ->click('Track order')
        ->assertSee($order->serial_number)
        ->assertSee('Shipped');

    // ── And staff see the finished order in the panel ────────────────────
    $this->actingAs($staff);

    visit("/admin/orders/{$order->getKey()}")
        ->assertSee($order->serial_number)
        ->assertSee('Shipped');
});
