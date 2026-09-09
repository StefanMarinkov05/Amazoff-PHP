<?php

declare(strict_types=1);

use App\Actions\Gdpr\EraseCustomer;
use App\Enums\ReturnStatus;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderReturn;
use App\Models\ProductReview;
use App\Models\User;
use App\Models\WishlistItem;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * GDPR Art. 17 erasure (ADR-0019). Each test checks one table's outcome;
 * the "proven red by removing the mechanism" cases are marked.
 */

beforeEach(fn () => $this->seed([PermissionSeeder::class, RoleSeeder::class]));

it('anonymises the customer\'s order and its addresses but keeps the financial figures', function (): void {
    $user = User::factory()->create();
    // OrderFactory fills anonymized_at by default; a live order has none.
    $order = Order::factory()->for($user)->create([
        'anonymized_at' => null,
        'email' => 'real@example.com',
        'first_name' => 'Real',
        'last_name' => 'Name',
        'phone' => '+359888000111',
        'customer_note' => 'leave at the door',
    ]);
    $totalBefore = $order->total_amount;
    OrderAddress::factory()->for($order)->create([
        'type' => 'delivery',
        'city' => 'Sofia',
        'postcode' => '1000',
        'street' => 'ul. Test 1',
        'country' => 'BG',
    ]);

    app(EraseCustomer::class)->handle($user);

    $order->refresh();
    expect($order->exists)->toBeTrue()
        ->and($order->user_id)->toBeNull()
        ->and($order->email)->toBe("erased-{$order->getKey()}@anonymized.invalid")
        ->and($order->first_name)->toBe('[erased]')
        ->and($order->last_name)->toBe('[erased]')
        ->and($order->phone)->toBe('')
        ->and($order->customer_note)->toBeNull()
        ->and($order->anonymized_at)->not->toBeNull()
        // the financial record is untouched
        ->and($order->total_amount)->toBe($totalBefore);

    $address = $order->orderAddresses()->first();
    expect($address->first_name)->toBe('[erased]')
        ->and($address->city)->toBe('[erased]')
        ->and($address->postcode)->toBe('[erased]')
        ->and($address->street)->toBeNull()
        ->and($address->country)->toBe('BG');  // kept — place of supply
});

it('scrubs the free text on a return but keeps the refund record (ADR-0020)', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create(['anonymized_at' => null]);
    $return = OrderReturn::factory()->refunded()->create([
        'order_id' => $order->getKey(),
        'reason' => 'the jumper unravelled after one wash',
        'resolution_note' => 'customer says it unravelled — approved',
        'refunded_amount' => '42.00',
    ]);

    app(EraseCustomer::class)->handle($user);

    $return->refresh();
    expect($return->reason)->toBe('[erased]')
        ->and($return->resolution_note)->toBeNull()
        ->and($return->status)->toBe(ReturnStatus::Refunded)
        ->and((string) $return->refunded_amount)->toBe('42.00')
        ->and($return->resolved_at)->not->toBeNull();
});

it('does not re-anonymise an already-anonymised order', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create([
        'anonymized_at' => now()->subYear(),
        'email' => 'erased-999@anonymized.invalid',
    ]);
    $stamp = $order->anonymized_at;

    app(EraseCustomer::class)->handle($user);

    expect($order->refresh()->anonymized_at->timestamp)->toBe($stamp->timestamp);
});

it('keeps a review but strips its author name', function (): void {
    $user = User::factory()->create();
    $review = ProductReview::factory()->for($user)->create([
        'author_name' => 'Jane Customer',
        'rating' => 5,
        'body' => 'Great product.',
    ]);

    app(EraseCustomer::class)->handle($user);

    $review->refresh();
    expect($review->exists)->toBeTrue()
        ->and($review->user_id)->toBeNull()
        ->and($review->author_name)->toBe('Anonymous')
        ->and($review->body)->toBe('Great product.')
        ->and($review->rating)->toBe(5);
});

it('hard-deletes newsletter and contact rows matched by id or email', function (): void {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $byId = NewsletterSubscriber::factory()->create(['user_id' => $user->id, 'email' => 'other@example.com']);
    $byEmail = NewsletterSubscriber::factory()->create(['user_id' => null, 'email' => 'gone@example.com']);
    $keep = NewsletterSubscriber::factory()->create(['user_id' => null, 'email' => 'someone-else@example.com']);
    $message = ContactMessage::factory()->create(['user_id' => null, 'email' => 'gone@example.com']);

    app(EraseCustomer::class)->handle($user);

    expect(NewsletterSubscriber::find($byId->id))->toBeNull()
        ->and(NewsletterSubscriber::find($byEmail->id))->toBeNull()
        ->and(NewsletterSubscriber::find($keep->id))->not->toBeNull()
        ->and(ContactMessage::find($message->id))->toBeNull();
});

it('deletes the user row and cascades its addresses, cart and wishlist', function (): void {
    $user = User::factory()->create();
    $wishlistItem = WishlistItem::factory()->for($user)->create();
    $user->addresses()->createMany([
        ['label' => 'Home', 'first_name' => 'A', 'last_name' => 'B', 'phone' => '1', 'country' => 'BG', 'city' => 'Sofia', 'postcode' => '1000', 'street' => 'x'],
    ]);

    app(EraseCustomer::class)->handle($user);

    expect(User::withTrashed()->find($user->id))->toBeNull()
        ->and(WishlistItem::find($wishlistItem->id))->toBeNull()
        ->and($user->addresses()->count())->toBe(0);
});

it('refuses a Filament-path erasure without the erase_user permission', function (): void {
    $actor = User::factory()->create();          // holds nothing
    $target = User::factory()->create();

    expect(fn () => app(EraseCustomer::class)->handle($target, $actor))
        ->toThrow(AuthorizationException::class);

    // proven red by removing: without the Gate::authorize call in the Action,
    // this passes for the wrong reason. The self-service path (no actor) is
    // the other branch and is covered by DeleteAccountTest.
    expect(User::withTrashed()->find($target->id))->not->toBeNull();
});

it('allows an administrator to erase another customer', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $target = User::factory()->create();

    app(EraseCustomer::class)->handle($target, $admin);

    expect(User::withTrashed()->find($target->id))->toBeNull();
});
