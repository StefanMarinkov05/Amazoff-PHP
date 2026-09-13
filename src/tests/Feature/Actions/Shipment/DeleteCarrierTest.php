<?php

declare(strict_types=1);

use App\Actions\Shipment\DeleteCarrier;
use App\Exceptions\CarrierCannotBeDeletedException;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\Shipment;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * EditCarrier's default DeleteAction previously called $record->delete()
 * directly, surfacing shipments.carrier_id's foreign key as an uncaught
 * QueryException (1451) instead of a message naming the dependency.
 * DeleteCarrier is what EditCarrier now routes through instead.
 *
 * orders.carrier_id is deliberately not covered by a refusal test here — it
 * is nullOnDelete(), so an order referencing this carrier does not block
 * the delete, only sets its carrier_id to null.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('deletes a carrier with no shipments', function (): void {
    $carrier = Carrier::factory()->create();

    app(DeleteCarrier::class)->handle($carrier, null);

    expect(Carrier::find($carrier->getKey()))->toBeNull();
});

it('refuses a carrier with a shipment and writes nothing', function (): void {
    $carrier = Carrier::factory()->create();
    Shipment::factory()->create(['carrier_id' => $carrier->getKey()]);

    expect(fn () => app(DeleteCarrier::class)->handle($carrier, null))
        ->toThrow(CarrierCannotBeDeletedException::class);

    expect(Carrier::find($carrier->getKey()))->not->toBeNull();
});

it('deletes a carrier an order references, nulling the order instead of blocking', function (): void {
    $carrier = Carrier::factory()->create();
    $order = Order::factory()->create(['carrier_id' => $carrier->getKey()]);

    app(DeleteCarrier::class)->handle($carrier, null);

    expect(Carrier::find($carrier->getKey()))->toBeNull();
    expect($order->refresh()->carrier_id)->toBeNull();
});

it('denies an actor without delete_carrier', function (): void {
    $carrier = Carrier::factory()->create();
    $actor = catalogueActor('update_carrier');

    expect(fn () => app(DeleteCarrier::class)->handle($carrier, $actor))
        ->toThrow(AuthorizationException::class);

    expect(Carrier::find($carrier->getKey()))->not->toBeNull();
});

it('allows an actor holding delete_carrier', function (): void {
    $carrier = Carrier::factory()->create();
    $actor = catalogueActor('delete_carrier');

    app(DeleteCarrier::class)->handle($carrier, $actor);

    expect(Carrier::find($carrier->getKey()))->toBeNull();
});
