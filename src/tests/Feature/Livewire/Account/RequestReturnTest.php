<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Livewire\Account\RequestReturn;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\User;
use Livewire\Livewire;

/*
 * RequestReturn — /account/orders/{order}/return (ADR-0020). Scoped the same
 * way OrderDetails is: order() starts from auth()->user()->orders(), so
 * another customer's id is a 404. A ReturnNotAllowedException from the Action
 * surfaces as a form error, never a 500.
 */

it('lets the owner submit a return for a delivered in-window order', function (): void {
    $user = User::factory()->create();
    $order = deliveredOrderForReturn(customer: $user, quantity: 2);
    $line = $order->orderItems->first();

    Livewire::actingAs($user)
        ->test(RequestReturn::class, ['order' => $order->id])
        ->set("quantities.{$line->id}", 1)
        ->set('reason', 'Not as described')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('received');

    $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
    expect($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->returnItems)->toHaveCount(1);
});

it('404s for another customer\'s order', function (): void {
    $user = User::factory()->create();
    $theirs = Order::factory()->create(['user_id' => User::factory()->create()->id]);

    Livewire::actingAs($user)
        ->test(RequestReturn::class, ['order' => $theirs->id])
        ->assertStatus(404);
});

it('renders the reason, not the form, for an order outside the window', function (): void {
    $user = User::factory()->create();
    $order = deliveredOrderForReturn(customer: $user, deliveredAt: now()->subDays(30));

    Livewire::actingAs($user)
        ->test(RequestReturn::class, ['order' => $order->id])
        ->assertSee('not eligible')
        ->assertDontSee('Submit return request');
});

it('surfaces an Action refusal as a form error, not a 500', function (): void {
    $user = User::factory()->create();
    $order = deliveredOrderForReturn(customer: $user, quantity: 1);
    $line = $order->orderItems->first();

    // The order is delivered and in-window, so the component renders the
    // form — but the order flips to Cancelled between render and submit.
    $component = Livewire::actingAs($user)
        ->test(RequestReturn::class, ['order' => $order->id])
        ->set("quantities.{$line->id}", 1)
        ->set('reason', 'changed my mind');

    $order->update(['status' => OrderStatus::Returned]);

    $component->call('submit')->assertHasErrors('reason');

    expect(OrderReturn::where('order_id', $order->id)->count())->toBe(0);
});
