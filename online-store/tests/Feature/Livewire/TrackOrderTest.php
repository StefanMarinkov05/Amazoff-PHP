<?php

declare(strict_types=1);

use App\Livewire\Orders\TrackOrder;
use App\Models\Order;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Public tracking is the one order route deliberately open to anyone, so the
 * rules CLAUDE.md states about it are the whole of what is worth testing:
 *
 *   1. Order number *and* email — the serial alone must never be enough,
 *      because `orders.serial_number` is sequential.
 *   2. The refusal must not distinguish "no such order" from "wrong email",
 *      or the page becomes an oracle for which serials exist.
 *   3. It must be rate limited, or it is that oracle at HTTP speed.
 *   4. It must not disclose the address, phone, name, or contents — email
 *      possession is a weaker claim than a session.
 *
 * Each test below goes red if its mechanism is removed. The success case is
 * the control: without it, a component that refused *everything* would pass
 * every denial test here.
 */

beforeEach(function (): void {
    RateLimiter::clear('track-order|127.0.0.1');
});

function trackableOrder(array $overrides = []): Order
{
    /** @var Order $order */
    $order = Order::factory()->create(array_merge([
        'email' => 'buyer@example.com',
    ], $overrides));

    return $order;
}

it('finds an order when the serial number and email both match', function (): void {
    $order = trackableOrder();

    Livewire::test(TrackOrder::class)
        ->set('serial_number', $order->serial_number)
        ->set('email', 'buyer@example.com')
        ->call('track')
        ->assertHasNoErrors()
        ->assertSet('foundOrderId', $order->getKey())
        ->assertSee($order->serial_number);
});

it('refuses a real serial number with the wrong email', function (): void {
    $order = trackableOrder();

    Livewire::test(TrackOrder::class)
        ->set('serial_number', $order->serial_number)
        ->set('email', 'attacker@example.com')
        ->call('track')
        ->assertHasErrors('serial_number')
        ->assertSet('foundOrderId', null)
        ->assertDontSee($order->serial_number);
});

it('refuses a real email with the wrong serial number', function (): void {
    trackableOrder();

    Livewire::test(TrackOrder::class)
        ->set('serial_number', 'ORD-999999')
        ->set('email', 'buyer@example.com')
        ->call('track')
        ->assertHasErrors('serial_number')
        ->assertSet('foundOrderId', null);
});

/*
 * The oracle test. A wrong email against a *real* order and a serial that
 * matches *nothing* must be indistinguishable — if the two messages differ,
 * an attacker walking the sequential range learns which serials exist
 * without ever knowing an email.
 */
it('gives the same refusal for a wrong email as for a nonexistent order', function (): void {
    $order = trackableOrder();

    $wrongEmail = Livewire::test(TrackOrder::class)
        ->set('serial_number', $order->serial_number)
        ->set('email', 'attacker@example.com')
        ->call('track')
        ->errors()
        ->get('serial_number');

    RateLimiter::clear('track-order|127.0.0.1');

    $noSuchOrder = Livewire::test(TrackOrder::class)
        ->set('serial_number', 'ORD-999999')
        ->set('email', 'attacker@example.com')
        ->call('track')
        ->errors()
        ->get('serial_number');

    expect($wrongEmail)->toBe($noSuchOrder);
});

it('throttles repeated failed lookups', function (): void {
    trackableOrder();

    $component = Livewire::test(TrackOrder::class);

    foreach (range(1, 5) as $attempt) {
        $component
            ->set('serial_number', "ORD-00000{$attempt}")
            ->set('email', 'attacker@example.com')
            ->call('track');
    }

    $component
        ->set('serial_number', 'ORD-000006')
        ->set('email', 'attacker@example.com')
        ->call('track')
        ->assertHasErrors('serial_number');

    expect($component->errors()->get('serial_number'))
        ->toContain('Too many attempts. Please try again in a minute.');
});

/*
 * A successful lookup clears the counter, so a customer who mistypes their
 * email twice and then gets it right is not locked out of their own order.
 */
it('clears the throttle counter after a successful lookup', function (): void {
    $order = trackableOrder();

    $component = Livewire::test(TrackOrder::class);

    foreach (range(1, 3) as $attempt) {
        $component
            ->set('serial_number', "ORD-00000{$attempt}")
            ->set('email', 'wrong@example.com')
            ->call('track');
    }

    $component
        ->set('serial_number', $order->serial_number)
        ->set('email', 'buyer@example.com')
        ->call('track')
        ->assertHasNoErrors();

    expect(RateLimiter::attempts('track-order|127.0.0.1'))->toBe(0);
});

/*
 * §34 / the class docblock: tracking answers "where is my order", not "what
 * did they buy and where do they live". Email possession is a weaker claim
 * than an authenticated session, so it unlocks correspondingly less.
 */
it('does not disclose the customer address or phone number', function (): void {
    $order = trackableOrder();

    $order->orderAddresses()->create([
        'type' => 'delivery',
        'first_name' => 'Zoltan',
        'last_name' => 'Discloseable',
        'phone' => '+359888777666',
        'street' => 'Secret Street 42',
        'city' => 'Plovdiv',
        'postcode' => '4000',
        'country' => 'BG',
    ]);

    Livewire::test(TrackOrder::class)
        ->set('serial_number', $order->serial_number)
        ->set('email', 'buyer@example.com')
        ->call('track')
        ->assertSee($order->serial_number)
        ->assertDontSee('Secret Street 42')
        ->assertDontSee('+359888777666')
        ->assertDontSee('Zoltan');
});

/*
 * `foundOrderId` is the entitlement. Without #[Locked] a crafted Livewire
 * payload could set it directly and read any order without ever supplying an
 * email — the same client-writable-identifier shape as SEC-001 and SEC-002.
 */
it('refuses a client write to the entitlement property', function (): void {
    $other = trackableOrder(['email' => 'someone-else@example.com']);

    Livewire::test(TrackOrder::class)
        ->set('foundOrderId', $other->getKey());
})->throws(CannotUpdateLockedPropertyException::class);

it('requires both fields', function (): void {
    Livewire::test(TrackOrder::class)
        ->set('serial_number', '')
        ->set('email', '')
        ->call('track')
        ->assertHasErrors(['serial_number', 'email']);
});
