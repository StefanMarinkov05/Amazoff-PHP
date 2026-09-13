<?php

declare(strict_types=1);

use App\Livewire\Checkout\CheckoutPage;
use Livewire\Livewire;

/*
 * SEC-014. `$carrier_id` and `$selected_address_id` were `?int`, bound to
 * `wire:model.live` fields the client drives directly — the same incident
 * class as `ProductDetails::$quantity`: Livewire assigns the raw client
 * value to the typed property before any of this class's own code runs, so
 * a number too large for PHP to represent as an int threw an uncaught
 * TypeError at hydration, confirmed live before this fix. Widened to
 * `mixed`; `updated()`/`updatedSelectedAddressId()` normalise back to a
 * real id or null immediately after, and the `rules()` entry
 * (`carrier_id`: `integer`, `exists`) still guards what `placeOrder()`
 * accepts.
 */

it('does not crash when carrier_id is set to a number too large for PHP to represent as an int', function (): void {
    Livewire::test(CheckoutPage::class)
        ->set('carrier_id', '99999999999999999999999999999999')
        ->assertOk()
        ->assertSet('carrier_id', null);
});

it('does not crash when selected_address_id is set to a number too large for PHP to represent as an int', function (): void {
    Livewire::test(CheckoutPage::class)
        ->set('selected_address_id', '99999999999999999999999999999999')
        ->assertOk()
        ->assertSet('selected_address_id', null);
});
