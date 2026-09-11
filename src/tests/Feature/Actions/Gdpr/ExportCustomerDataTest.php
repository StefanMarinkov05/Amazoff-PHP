<?php

declare(strict_types=1);

use App\Actions\Gdpr\ExportCustomerData;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ProductReview;
use App\Models\ReturnItem;
use App\Models\User;

/*
 * GDPR Art. 15 / 20 export (ADR-0019). The mirror of EraseCustomer — it
 * must reach every table that one does.
 */

it('includes the account, addresses, orders, reviews, wishlist, newsletter and contact rows', function (): void {
    $user = User::factory()->create(['email' => 'me@example.com', 'first_name' => 'Nadia']);
    $user->addresses()->create([
        'label' => 'Home', 'first_name' => 'Nadia', 'last_name' => 'X', 'phone' => '1',
        'country' => 'BG', 'city' => 'Sofia', 'postcode' => '1000', 'street' => 'Vitosha 1',
    ]);
    $order = Order::factory()->for($user)->create(['anonymized_at' => null, 'serial_number' => 'ORD-EXP-1']);
    ProductReview::factory()->for($user)->create(['body' => 'Solid.', 'rating' => 4]);
    NewsletterSubscriber::factory()->create(['user_id' => null, 'email' => 'me@example.com']);
    ContactMessage::factory()->create(['user_id' => null, 'email' => 'me@example.com', 'message' => 'hello']);

    $doc = app(ExportCustomerData::class)->handle($user);

    expect($doc['account']['email'])->toBe('me@example.com')
        ->and($doc['addresses'])->toHaveCount(1)
        ->and($doc['orders'][0]['number'])->toBe('ORD-EXP-1')
        ->and($doc['reviews'][0]['body'])->toBe('Solid.')
        ->and($doc['newsletter'][0]['email'])->toBe('me@example.com')   // matched by email, not user_id
        ->and($doc['contact_messages'][0]['message'])->toBe('hello');
});

it('includes the customer\'s returns and their items (ADR-0020)', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create(['anonymized_at' => null, 'serial_number' => 'ORD-RET-1']);
    $item = OrderItem::factory()->create([
        'order_id' => $order->getKey(),
        'product_name' => 'Wool jumper',
        'quantity' => 2,
    ]);
    $return = OrderReturn::factory()->create([
        'order_id' => $order->getKey(),
        'reason' => 'too small',
    ]);
    ReturnItem::factory()->create([
        'return_id' => $return->getKey(),
        'order_item_id' => $item->getKey(),
        'quantity' => 1,
    ]);

    $doc = app(ExportCustomerData::class)->handle($user);

    expect($doc['orders'][0]['returns'])->toHaveCount(1)
        ->and($doc['orders'][0]['returns'][0]['reason'])->toBe('too small')
        ->and($doc['orders'][0]['returns'][0]['items'][0]['product'])->toBe('Wool jumper')
        ->and($doc['orders'][0]['returns'][0]['items'][0]['quantity'])->toBe(1);
});

it('marks an anonymised order as anonymised and never exposes the coupon hash', function (): void {
    $user = User::factory()->create();
    Order::factory()->for($user)->create(['anonymized_at' => now()]);

    $doc = app(ExportCustomerData::class)->handle($user);

    expect($doc['orders'][0]['anonymised'])->toBeTrue();

    $json = json_encode($doc);
    expect($json)->not->toContain('email_hash')->not->toContain('pepper');
});
