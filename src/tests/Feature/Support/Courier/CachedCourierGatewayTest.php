<?php

declare(strict_types=1);

use App\Contracts\CourierGateway;
use App\Support\Courier\CachedCourierGateway;
use App\Support\Courier\CourierCity;
use App\Support\Courier\CourierOffice;
use App\Support\Courier\CourierTrackingEvent;
use App\Support\Courier\DeliveryQuote;
use App\Support\Courier\ShipmentRequest;
use App\Support\Courier\ShipmentResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/*
 * What is ours here: that offices()/cities()/quote() are cached and
 * createShipment()/label()/track() are not — caching a write, or a
 * tracking poll meant to see the courier's current state, would be a bug.
 * The mapping itself is EcontGatewayTest's and SpeedyGatewayTest's
 * territory; this only counts calls to a spy.
 *
 * The real-cache-store tests below exist because `CACHE_STORE=array`
 * (phpunit.xml, for the whole suite) never serializes at all, so every test
 * above this comment would pass even if caching DTOs directly crashed on a
 * real cache hit in production — which it did. See
 * docs/how-to/troubleshooting/infra-and-environment.md, "A cached object
 * comes back as __PHP_Incomplete_Class".
 */

/** A CourierGateway that counts how many times each method actually ran. */
class CountingCourierGateway implements CourierGateway
{
    public int $citiesCalls = 0;

    public int $officesCalls = 0;

    public int $quoteCalls = 0;

    public int $createShipmentCalls = 0;

    public int $trackCalls = 0;

    public function code(): string
    {
        return 'counting';
    }

    public function cities(string $term): Collection
    {
        $this->citiesCalls++;

        return collect([new CourierCity(vendorId: '1', name: 'Sofia', postcode: '1000', country: 'BG')]);
    }

    public function offices(string $city, ?string $postcode = null): Collection
    {
        $this->officesCalls++;

        return collect([new CourierOffice(code: 'OFF1', name: 'Office 1', address: 'St 1', city: $city, postcode: '1000')]);
    }

    public function quote(ShipmentRequest $request): DeliveryQuote
    {
        $this->quoteCalls++;

        return new DeliveryQuote(amount: '5.00', currency: 'BGN');
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $this->createShipmentCalls++;

        return new ShipmentResult(shipmentNumber: 'S1', trackingNumber: 'T1', labelUrl: null, trackingUrl: null);
    }

    public function label(string $shipmentNumber): string
    {
        return '%PDF';
    }

    public function track(string $trackingNumber): Collection
    {
        $this->trackCalls++;

        /** @var Collection<int, CourierTrackingEvent> */
        return collect();
    }
}

function fakeCachedShipmentRequest(): ShipmentRequest
{
    return new ShipmentRequest(
        city: 'Sofia',
        postcode: '1000',
        country: 'BG',
        street: 'Vitosha 1',
        officeCode: null,
        receiverName: 'Ada Lovelace',
        receiverPhone: '+359888123456',
        weightGrams: 1000,
        codAmount: null,
    );
}

it('caches offices() so a second call for the same city does not reach the inner gateway', function (): void {
    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $cached->offices('Sofia');
    $cached->offices('Sofia');

    expect($inner->officesCalls)->toBe(1);
});

it('caches cities() the same way', function (): void {
    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $cached->cities('Sofia');
    $cached->cities('Sofia');

    expect($inner->citiesCalls)->toBe(1);
});

it('caches quote() for identical request inputs', function (): void {
    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $cached->quote(fakeCachedShipmentRequest());
    $cached->quote(fakeCachedShipmentRequest());

    expect($inner->quoteCalls)->toBe(1);
});

it('does not cache offices() across different cities', function (): void {
    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $cached->offices('Sofia');
    $cached->offices('Plovdiv');

    expect($inner->officesCalls)->toBe(2);
});

it('never caches createShipment() — every call is a real write', function (): void {
    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $cached->createShipment(fakeCachedShipmentRequest());
    $cached->createShipment(fakeCachedShipmentRequest());

    expect($inner->createShipmentCalls)->toBe(2);
});

it('never caches track() — a poll must see the courier\'s current state', function (): void {
    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $cached->track('T1');
    $cached->track('T1');

    expect($inner->trackCalls)->toBe(2);
});

/*
 * ── Real cache store — the round trip this project's own security default
 *    can break ─────────────────────────────────────────────────────────
 */

/**
 * Points the `Cache` facade at the real `file` store for the duration of
 * one test — `phpunit.xml` sets `CACHE_STORE=array` suite-wide, and the
 * `array` store never serializes (`'serialize' => false`, `config/cache.php`),
 * so it cannot exercise `config('cache.serializable_classes') === false`'s
 * `allowed_classes: false` restriction at all. `file` uses the same
 * `unserialize()`-based path the real `database` store does.
 */
function useRealFileCacheStore(): void
{
    config(['cache.default' => 'file']);
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    Cache::clearResolvedInstances();
    Cache::store('file')->flush();
}

it('survives a real cache-store round trip for offices(), not just the array-store test double', function (): void {
    useRealFileCacheStore();

    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $first = $cached->offices('Sofia');
    $second = $cached->offices('Sofia'); // served from the real file cache, not memory

    expect($first->first())->toBeInstanceOf(CourierOffice::class)
        ->and($second->first())->toBeInstanceOf(CourierOffice::class)
        ->and($second->first()->code)->toBe('OFF1')
        ->and($inner->officesCalls)->toBe(1);
});

it('survives a real cache-store round trip for quote()', function (): void {
    useRealFileCacheStore();

    $inner = new CountingCourierGateway;
    $cached = new CachedCourierGateway($inner);

    $first = $cached->quote(fakeCachedShipmentRequest());
    $second = $cached->quote(fakeCachedShipmentRequest());

    expect($first)->toBeInstanceOf(DeliveryQuote::class)
        ->and($second)->toBeInstanceOf(DeliveryQuote::class)
        ->and($second->amount)->toBe('5.00')
        ->and($inner->quoteCalls)->toBe(1);
});
