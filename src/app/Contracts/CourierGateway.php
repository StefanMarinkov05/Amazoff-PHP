<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\CourierUnavailableException;
use App\Support\Courier\CourierCity;
use App\Support\Courier\CourierOffice;
use App\Support\Courier\CourierTrackingEvent;
use App\Support\Courier\DeliveryQuote;
use App\Support\Courier\ShipmentRequest;
use App\Support\Courier\ShipmentResult;
use Illuminate\Support\Collection;

/**
 * §37 #14: both couriers behind one shared interface. Every method returns
 * this app's own DTOs, never a vendor's response shape — `EcontGateway` and
 * `SpeedyGateway` are where Econt's and Speedy's JSON gets translated, once
 * each, and nowhere else in the codebase parses either vendor's payload.
 *
 * `App\Support\Courier\CourierManager` is the only place this interface is
 * resolved from the container; a caller reaches it through the `Courier`
 * facade (reads) or a constructor-injected `CourierManager` (writes) —
 * never `app(CourierGateway::class)` directly, which cannot say which
 * carrier it means.
 */
interface CourierGateway
{
    /** The `carriers.code` this implementation serves — 'econt' or 'speedy'. */
    public function code(): string;

    /**
     * @return Collection<int, CourierCity>
     *
     * @throws CourierUnavailableException
     */
    public function cities(string $term): Collection;

    /**
     * @return Collection<int, CourierOffice>
     *
     * @throws CourierUnavailableException
     */
    public function offices(string $city, ?string $postcode = null): Collection;

    /** @throws CourierUnavailableException */
    public function quote(ShipmentRequest $request): DeliveryQuote;

    /** @throws CourierUnavailableException */
    public function createShipment(ShipmentRequest $request): ShipmentResult;

    /** @throws CourierUnavailableException */
    public function label(string $shipmentNumber): string;

    /**
     * @return Collection<int, CourierTrackingEvent>
     *
     * @throws CourierUnavailableException
     */
    public function track(string $trackingNumber): Collection;
}
