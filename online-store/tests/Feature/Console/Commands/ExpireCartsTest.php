<?php

declare(strict_types=1);

use App\Models\Cart;

it('deletes expired carts and reports the count', function (): void {
    Cart::factory()->count(2)->create(['expires_at' => now()->subDay()]);
    Cart::factory()->create(['expires_at' => now()->addDay()]);

    $this->artisan('carts:expire')
        ->expectsOutputToContain('Deleted 2 expired cart(s).')
        ->assertExitCode(0);

    expect(Cart::query()->count())->toBe(1);
});
