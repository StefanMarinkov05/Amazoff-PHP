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

it('does not silently treat a garbage-shaped quantity as "return 1"', function (): void {
    // SEC-016. $quantities is array<int, int> only by PHPDoc — Livewire
    // hydrates whatever the client sends, so a value can arrive as a nested
    // array. (int) of any non-empty array is always 1 in PHP, regardless of
    // its contents, so a garbage shape silently became a legitimate-looking
    // request to return exactly one unit — the same "wrong answer returns
    // 200" class test-for-input-crashes.md already documents for
    // ProductList::$attributeValueIds, confirmed live here before the fix.
    $user = User::factory()->create();
    $order = deliveredOrderForReturn(customer: $user, quantity: 2);
    $line = $order->orderItems->first();

    Livewire::actingAs($user)
        ->test(RequestReturn::class, ['order' => $order->id])
        ->set("quantities.{$line->id}", [['nested' => 'garbage']])
        ->set('reason', 'garbage nested array test')
        ->call('submit')
        ->assertHasErrors('quantities');

    expect(OrderReturn::where('order_id', $order->id)->count())->toBe(0);
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
