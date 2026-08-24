<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Carrier;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Two warehouse staff clicking "create shipment" on one order at the same
 * moment.
 *
 * Same shape as RecordPaymentConcurrencyTest: Order::shipment() is a HasOne
 * declaration, not a constraint, and `shipments` carries UNIQUE only on
 * tracking_number — which is null at creation and therefore constrains
 * nothing here (MySQL does not collide nulls). The orders lock is the whole
 * defence.
 *
 * A duplicate shipment is not a harmless extra row: for a COD order it is a
 * second courier consignment carrying a second cod_amount, so the customer is
 * asked to pay the total twice on the doorstep.
 */

afterEach(function (): void {
    Schema::disableForeignKeyConstraints();

    foreach (['shipment_tracking_events', 'shipments', 'order_items', 'orders', 'carriers'] as $table) {
        DB::table($table)->truncate();
    }

    Schema::enableForeignKeyConstraints();
});

it('lets exactly one of two simultaneous shipment creations through', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'payment_method' => PaymentMethod::Stripe,
        'total_amount' => '80.00',
    ]);

    $carrier = Carrier::factory()->create(['is_active' => true]);

    $outputs = runRaceWorkers([
        ['action' => 'create-shipment', 'ids' => [$order->getKey(), $carrier->getKey()]],
        ['action' => 'create-shipment', 'ids' => [$order->getKey(), $carrier->getKey()]],
    ]);

    expect(Shipment::where('order_id', $order->getKey())->count())
        ->toBe(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'OK')))
        ->toHaveCount(1, raceReport($outputs));

    expect($outputs->filter(fn (string $o): bool => str_contains($o, 'ShipmentNotAllowedException')))
        ->toHaveCount(1, raceReport($outputs));
});

it('never produces two cod consignments for one cash-on-delivery order', function (): void {
    // The consequence that makes the lock worth having, stated as its own
    // assertion: two rows here means the courier collects the total twice.
    $order = Order::factory()->create([
        'status' => OrderStatus::New,
        'payment_method' => PaymentMethod::CashOnDelivery,
        'total_amount' => '64.50',
    ]);

    $carrier = Carrier::factory()->create(['is_active' => true]);

    $outputs = runRaceWorkers([
        ['action' => 'create-shipment', 'ids' => [$order->getKey(), $carrier->getKey()]],
        ['action' => 'create-shipment', 'ids' => [$order->getKey(), $carrier->getKey()]],
    ]);

    $shipments = Shipment::where('order_id', $order->getKey())->get();

    expect($shipments)->toHaveCount(1, raceReport($outputs));

    // The sum the courier would collect across every consignment must equal
    // the order total exactly, not a multiple of it. Summed through Money
    // rather than Collection::sum() — this is arithmetic across rows, which
    // is exactly what CLAUDE.md forbids float for, and a plain sum() would
    // also format 64.50 as "64.5" and make the assertion about PHP's float
    // printing rather than about the shipments.
    $collectable = $shipments->reduce(
        fn (Money $carry, Shipment $s): Money => $carry->add(Money::of((string) $s->cod_amount)),
        Money::zero(),
    );

    expect((string) $collectable)->toBe('64.50', raceReport($outputs));
});
