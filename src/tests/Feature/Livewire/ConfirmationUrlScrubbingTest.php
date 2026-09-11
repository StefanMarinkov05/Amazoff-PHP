<?php

declare(strict_types=1);

use App\Livewire\Checkout\OrderConfirmation;
use App\Models\Order;

/*
 * SEC-012: Stripe appends `payment_intent`, `payment_intent_client_secret`
 * and `redirect_status` to whatever `return_url` it is given. The client
 * secret plus the publishable key (public by design) is enough to retrieve
 * the PaymentIntent from a browser and read its amount, currency and status
 * — confirmed by doing it. Left in the address bar it persists in browser
 * history, in the web server's access log (nginx logs the full request
 * line), and anywhere that log is shipped.
 *
 * OrderConfirmation reads none of the three: it resolves the order from the
 * `{order}` segment and the session claim, and reports the *webhook's*
 * payment status rather than `redirect_status`, because a redirect is
 * trivially forgeable by typing the URL. So they can be dropped with no loss.
 *
 * Driven as real HTTP requests rather than through `Livewire::test()`: the
 * test harness builds its own request and carries no query string into the
 * component, so the parameters this guard reads would never be present and
 * the redirect case would pass vacuously.
 */

function stripeReturnUrl(Order $order): string
{
    return route('checkout.confirmation', ['order' => $order->getKey()])
        .'?payment_intent=pi_x'
        .'&payment_intent_client_secret=pi_x_secret_SHOULDNOTPERSIST'
        .'&redirect_status=succeeded';
}

it('redirects away from a URL carrying Stripe redirect parameters', function (): void {
    /** @var Order $order */
    $order = Order::factory()->create();

    $this->withSession([OrderConfirmation::SESSION_KEY => $order->getKey()])
        ->get(stripeReturnUrl($order))
        ->assertRedirect(route('checkout.confirmation', ['order' => $order->getKey()]));
});

it('does not redirect when the URL is already clean', function (): void {
    // The control. Without it, a component that redirected unconditionally
    // would pass the case above while looping forever in a browser.
    /** @var Order $order */
    $order = Order::factory()->create();

    $this->withSession([OrderConfirmation::SESSION_KEY => $order->getKey()])
        ->get(route('checkout.confirmation', ['order' => $order->getKey()]))
        ->assertOk()
        ->assertSee($order->serial_number);
});

it('still refuses an order the visitor does not own, parameters or not', function (): void {
    // The scrub must not become a way around the entitlement check: a
    // stranger arriving with Stripe's parameters attached gets the same 404
    // as one arriving without them, not a redirect that confirms the order
    // exists.
    /** @var Order $order */
    $order = Order::factory()->create();

    $this->get(stripeReturnUrl($order))->assertNotFound();
});
