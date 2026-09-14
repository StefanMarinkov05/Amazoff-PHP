<?php

declare(strict_types=1);

namespace App\Support\Courier;

/**
 * One vendor-neutral city result. `vendorId` is opaque outside its own
 * gateway — Econt keys offices by an integer city id, Speedy by a site id —
 * so a lookup that needs it (`offices()`) is always made through the same
 * gateway that returned this city, never mixed across carriers.
 */
final readonly class CourierCity
{
    public function __construct(
        public string $vendorId,
        public string $name,
        public string $postcode,
        public string $country,
    ) {}
}
