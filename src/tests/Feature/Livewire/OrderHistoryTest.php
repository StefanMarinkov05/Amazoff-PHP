<?php

declare(strict_types=1);

use App\Livewire\Account\OrderHistory;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

/*
 * The entire point of this component is the scoping. CLAUDE.md:
 * "Scope queries to the user (auth()->user()->orders()->findOrFail($id)),
 * never Order::findOrFail($id)" — and `orders.serial_number` is sequential,
 * so an unscoped list would hand every customer the whole order book.
 *
 * The leak test below goes red the moment `$user->orders()` becomes
 * `Order::query()`. The "sees their own" test is its control: without it, a
 * component that rendered *nothing* would pass the leak test.
 */

it("lists the signed-in customer's own orders", function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Order $order */
    $order = Order::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(OrderHistory::class)
        ->assertSee($order->serial_number);
});

it("never shows another customer's orders", function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var User $stranger */
    $stranger = User::factory()->create();

    /** @var Order $mine */
    $mine = Order::factory()->create(['user_id' => $user->id]);
    /** @var Order $theirs */
    $theirs = Order::factory()->create(['user_id' => $stranger->id]);

    Livewire::actingAs($user)
        ->test(OrderHistory::class)
        ->assertSee($mine->serial_number)
        ->assertDontSee($theirs->serial_number);
});

/*
 * A guest order carries no user_id. It must not surface in anyone's account
 * list — it is reachable only through the session claim OrderConfirmation
 * honours, or through public tracking with the email.
 */
it('does not show guest orders to a signed-in customer', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Order $guestOrder */
    $guestOrder = Order::factory()->create(['user_id' => null]);

    Livewire::actingAs($user)
        ->test(OrderHistory::class)
        ->assertDontSee($guestOrder->serial_number);
});

it('tells a customer with no orders that they have none', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    // Someone else's order exists, so an empty result proves scoping rather
    // than an empty table.
    Order::factory()->create(['user_id' => User::factory()->create()->id]);

    Livewire::actingAs($user)
        ->test(OrderHistory::class)
        ->assertSee('You have not placed an order yet.');
});

it('requires authentication', function (): void {
    $this->get('/account/orders')->assertRedirect('/login');
});
