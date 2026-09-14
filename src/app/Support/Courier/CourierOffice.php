<?php

declare(strict_types=1);

namespace App\Support\Courier;

/**
 * One vendor-neutral courier office. `code` is what `order_addresses
 * .courier_office_code` stores and what a later `createShipment()` call
 * addresses the office by — it is the vendor's own office code, not a
 * surrogate, because that is the value the courier's own systems need back.
 */
final readonly class CourierOffice
{
    public function __construct(
        public string $code,
        public string $name,
        public string $address,
        public string $city,
        public string $postcode,
        public ?int $maxWeightGrams = null,
        public bool $supportsCod = true,
    ) {}
}
