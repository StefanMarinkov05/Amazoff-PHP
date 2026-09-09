<?php

declare(strict_types=1);

use App\Actions\Returns\RequestReturn;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Exceptions\ReturnNotAllowedException;
use App\Models\OrderReturn;
use App\Models\User;

/*
 * RequestReturn — the customer-facing half of the 14-day right of withdrawal
 * (ADR-0020, CRD Arts. 9–15). Each refusal is asserted, and the guard for it
 * proven load-bearing by the "writes nothing" partner assertion — a refusal
 * that still wrote a `returns` row would be worse than no guard.
 */

it('writes a return request and its items for a delivered order in the window', function (): void {
    $customer = User::factory()->create();
    $order = deliveredOrderForReturn(customer: $customer, quantity: 3);
    $line = $order->orderItems->first();

    $return = app(RequestReturn::class)->handle($order, [$line->id => 2], 'Wrong size', $customer);

    expect($return)->toBeInstanceOf(OrderReturn::class)
        ->and($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->reason)->toBe('Wrong size')
        ->and($return->order_id)->toBe($order->getKey())
        ->and($return->requested_at)->not->toBeNull()
        ->and($return->returnItems)->toHaveCount(1)
        ->and($return->returnItems->first()->order_item_id)->toBe($line->id)
        ->and($return->returnItems->first()->quantity)->toBe(2);
});

it('purifies the reason before storing it', function (): void {
    $order = deliveredOrderForReturn();
    $line = $order->orderItems->first();

    $return = app(RequestReturn::class)->handle(
        $order,
        [$line->id => 1],
        'Broke <script>alert(1)</script> on arrival',
        $order->user,
    );

    expect($return->reason)->not->toContain('<script>')
        ->and($return->reason)->toContain('Broke');
});

it('refuses a return for an order that is not delivered', function (): void {
    $order = deliveredOrderForReturn();
    $order->update(['status' => OrderStatus::Shipped]);
    $line = $order->orderItems->first();

    expect(fn () => app(RequestReturn::class)->handle($order, [$line->id => 1], 'x', $order->user))
        ->toThrow(ReturnNotAllowedException::class);

    expect(OrderReturn::count())->toBe(0);
});

it('refuses a return after the 14-day window has passed', function (): void {
    $order = deliveredOrderForReturn(deliveredAt: now()->subDays(15));
    $line = $order->orderItems->first();

    expect(fn () => app(RequestReturn::class)->handle($order, [$line->id => 1], 'x', $order->user))
        ->toThrow(ReturnNotAllowedException::class);

    expect(OrderReturn::count())->toBe(0);
});

it('accepts a return on the last day of the window', function (): void {
    $order = deliveredOrderForReturn(deliveredAt: now()->subDays(14)->addHour());
    $line = $order->orderItems->first();

    $return = app(RequestReturn::class)->handle($order, [$line->id => 1], 'in time', $order->user);

    expect($return->status)->toBe(ReturnStatus::Requested);
});

it('refuses when nothing is selected', function (): void {
    $order = deliveredOrderForReturn();
    $line = $order->orderItems->first();

    expect(fn () => app(RequestReturn::class)->handle($order, [$line->id => 0], 'x', $order->user))
        ->toThrow(ReturnNotAllowedException::class);

    expect(OrderReturn::count())->toBe(0);
});

it('refuses a line that is not on the order', function (): void {
    $order = deliveredOrderForReturn();
    $strangerLine = deliveredOrderForReturn()->orderItems->first();

    expect(fn () => app(RequestReturn::class)->handle($order, [$strangerLine->id => 1], 'x', $order->user))
        ->toThrow(ReturnNotAllowedException::class);

    expect(OrderReturn::where('order_id', $order->getKey())->count())->toBe(0);
});

it('refuses more than was ordered', function (): void {
    $order = deliveredOrderForReturn(quantity: 2);
    $line = $order->orderItems->first();

    expect(fn () => app(RequestReturn::class)->handle($order, [$line->id => 3], 'x', $order->user))
        ->toThrow(ReturnNotAllowedException::class);

    expect(OrderReturn::count())->toBe(0);
});

it('caps the remaining quantity across two sequential returns', function (): void {
    $order = deliveredOrderForReturn(quantity: 3);
    $line = $order->orderItems->first();

    app(RequestReturn::class)->handle($order, [$line->id => 2], 'first', $order->user);

    // 1 left; asking for 2 is refused, asking for 1 is fine.
    expect(fn () => app(RequestReturn::class)->handle($order->fresh(), [$line->id => 2], 'second', $order->user))
        ->toThrow(ReturnNotAllowedException::class);

    $second = app(RequestReturn::class)->handle($order->fresh(), [$line->id => 1], 'second', $order->user);

    expect($second->status)->toBe(ReturnStatus::Requested)
        ->and(OrderReturn::where('order_id', $order->getKey())->count())->toBe(2);
});

it('frees the quantity again when an earlier request was denied', function (): void {
    $order = deliveredOrderForReturn(quantity: 2);
    $line = $order->orderItems->first();

    $first = app(RequestReturn::class)->handle($order, [$line->id => 2], 'first', $order->user);
    $first->update(['status' => ReturnStatus::Denied]);

    $second = app(RequestReturn::class)->handle($order->fresh(), [$line->id => 2], 'again', $order->user);

    expect($second->returnItems->first()->quantity)->toBe(2);
});
