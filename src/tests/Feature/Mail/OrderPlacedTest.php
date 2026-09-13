<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Payment;

/*
 * The order-confirmation email (ADR-0019, CRD Art. 8(7)). That it is queued
 * from checkout is covered by CheckoutTest; this is the content contract.
 */

function placedOrder(array $overrides = []): Order
{
    $order = Order::factory()->create([
        'anonymized_at' => null,
        'email' => 'buyer@example.com',
        'first_name' => 'Nadia',
        'serial_number' => 'ORD-TEST-42',
        ...$overrides,
    ]);
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Aeropress Go',
        'variation_name' => 'Travel / Grey',
        'product_sku' => 'AERO-GO-GREY',
        'quantity' => 2,
    ]);
    OrderAddress::factory()->for($order)->create(['type' => 'delivery', 'city' => 'Plovdiv']);

    return $order;
}

it('renders the full order without any card data', function (): void {
    $order = placedOrder();
    Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Paid,
    ]);

    $rendered = (new OrderPlaced($order))->render();

    expect($rendered)
        ->toContain('ORD-TEST-42')
        ->toContain('Aeropress Go')
        ->toContain('Travel / Grey')
        ->toContain('AERO-GO-GREY')
        ->toContain('Plovdiv')
        ->toContain('withdraw')                 // CRD withdrawal info
        ->toContain('buyer@example.com')
        // never any payment-token or card-number data (the method *label*
        // "Card payment" is fine; a PAN or an intent id is not)
        ->not->toContain('pi_')
        ->not->toContain('client_secret')
        ->not->toMatch('/\b\d{13,19}\b/');       // no bare card-length digit run
});

it('has the order number as its subject', function (): void {
    // The recipient is set at the call site (Mail::to(...)) and asserted in
    // CheckoutTest; the mailable owns the subject.
    expect((new OrderPlaced(placedOrder()))->envelope()->subject)
        ->toBe('Your order ORD-TEST-42');
});

/*
 * A rendering fact, not a claim about the flow. Since ADR-0022 a card
 * order's confirmation is only sent once the payment reaches Paid, so the
 * real flow no longer renders this template against a Pending card payment.
 * The branch is kept and tested because the mailable re-reads the order when
 * the queued job runs: a refund or a dispute landing between the transition
 * and the send can still put a non-Paid status in front of this template,
 * and it must render something truthful rather than blank.
 */
it('renders a non-paid card payment truthfully rather than blank', function (): void {
    $order = placedOrder();
    Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Stripe,
        'status' => PaymentStatus::Pending,
    ]);

    expect((new OrderPlaced($order))->render())->toContain('confirmation pending');
});

it('tells a COD customer they pay the courier', function (): void {
    $order = placedOrder();
    Payment::factory()->for($order)->create([
        'method' => PaymentMethod::CashOnDelivery,
        'status' => PaymentStatus::Pending,
    ]);

    expect((new OrderPlaced($order))->render())->toContain('pay the courier');
});

it('shows the billing address only when it differs from delivery', function (): void {
    $order = placedOrder();
    OrderAddress::factory()->for($order)->create([
        'type' => 'billing',
        'city' => 'Sofia',            // different city
        'street' => 'bul. Bulgaria 1',
    ]);

    expect((new OrderPlaced($order))->render())->toContain('Billing address');

    $same = placedOrder(['serial_number' => 'ORD-TEST-43', 'email' => 'x@example.com']);
    $d = $same->orderAddresses()->where('type', 'delivery')->first();
    OrderAddress::factory()->for($same)->create([
        'type' => 'billing',
        'city' => $d->city,
        'postcode' => $d->postcode,
        'street' => $d->street,
        'first_name' => $d->first_name,
        'last_name' => $d->last_name,
    ]);

    expect((new OrderPlaced($same))->render())->not->toContain('Billing address');
});
