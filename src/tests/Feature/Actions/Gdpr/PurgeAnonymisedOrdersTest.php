<?php

declare(strict_types=1);

use App\Actions\Gdpr\PurgeAnonymisedOrders;
use App\Models\Order;
use App\Models\OrderItem;

/*
 * The retention purge (ADR-0019). The exact period is config-driven; these
 * tests set it explicitly rather than relying on the default.
 */

it('deletes an anonymised order older than the retention period, with its children', function (): void {
    config(['gdpr.order_retention_years' => 5]);

    $old = Order::factory()->create(['anonymized_at' => now()->subYears(6)]);
    OrderItem::factory()->for($old)->create();
    $recent = Order::factory()->create(['anonymized_at' => now()->subYear()]);
    $live = Order::factory()->create(['anonymized_at' => null]);

    $deleted = app(PurgeAnonymisedOrders::class)->handle();

    expect($deleted)->toBe(1)
        ->and(Order::find($old->id))->toBeNull()
        ->and($old->orderItems()->count())->toBe(0)          // cascade
        ->and(Order::find($recent->id))->not->toBeNull()
        ->and(Order::find($live->id))->not->toBeNull();
});

it('does nothing and reports disabled when the period is null', function (): void {
    config(['gdpr.order_retention_years' => null]);
    Order::factory()->create(['anonymized_at' => now()->subYears(20)]);

    expect(app(PurgeAnonymisedOrders::class)->handle())->toBeNull()
        ->and(Order::count())->toBe(1);
});

it('rejects a non-positive period rather than guessing', function (): void {
    config(['gdpr.order_retention_years' => 0]);

    expect(fn () => app(PurgeAnonymisedOrders::class)->handle())
        ->toThrow(RuntimeException::class);
});

it('is exposed as orders:purge-anonymised', function (): void {
    config(['gdpr.order_retention_years' => 5]);
    Order::factory()->create(['anonymized_at' => now()->subYears(6)]);

    $this->artisan('orders:purge-anonymised')
        ->expectsOutputToContain('Purged 1 anonymised order')
        ->assertSuccessful();
});
