<?php

declare(strict_types=1);

namespace App\Support\Courier;

/**
 * Everything a gateway needs to quote or create a shipment, gathered once by
 * the caller so `quote()` and `createShipment()` take the same shape — a
 * quote that priced one set of inputs and a shipment created from another
 * is exactly the mismatch `CreateOrder`'s single-resolution rule (see its
 * class docblock) already avoids for line pricing.
 *
 * Plain scalars rather than an `OrderAddress` model: `quote()` is called
 * from checkout, before an order — or any address row — exists.
 * `weightGrams` is the parcel's total weight, already summed across the
 * cart by the caller; a gateway does not know about `CartItem` or
 * `ProductVariation`.
 */
final readonly class ShipmentRequest
{
    public function __construct(
        public string $city,
        public string $postcode,
        public string $country,
        public ?string $street,
        public ?string $officeCode,
        public string $receiverName,
        public string $receiverPhone,
        public int $weightGrams,
        public ?string $codAmount,
    ) {}
}
