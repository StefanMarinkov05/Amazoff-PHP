<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;

/*
 * orders:expire-unpaid — the scheduled half of ADR-0022. The Action's own
 * behaviour is ExpireUnpaidOrdersTest; what this proves is that the command
 * reaches it and reports a real count rather than only exiting 0.
 */

it('cancels expired unpaid orders and reports the count', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);

    $variation = variationWithStock(current: 10, reserved: 2);
    $order = orderWithVariationLine($variation, OrderStatus::AwaitingPayment, 2);
    $order->update(['anonymized_at' => null]);
    OrderStatusHistory::factory()->create([
        'order_id' => $order->getKey(),
        'previous_status' => OrderStatus::New,
        'new_status' => OrderStatus::AwaitingPayment,
        'created_at' => now()->subMinutes(30),
    ]);

    $this->artisan('orders:expire-unpaid')
        ->expectsOutputToContain('Cancelled 1 unpaid order(s) and released their stock.')
        ->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('reports zero rather than failing when there is nothing to sweep', function (): void {
    config(['orders.unpaid_ttl_minutes' => 10]);

    $this->artisan('orders:expire-unpaid')
        ->expectsOutputToContain('Cancelled 0 unpaid order(s)')
        ->assertSuccessful();

    expect(Order::count())->toBe(0);
});
