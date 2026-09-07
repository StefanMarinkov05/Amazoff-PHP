<?php

declare(strict_types=1);

namespace App\Support\Courier;

/**
 * `amount` is a decimal string, never a float — the same discipline
 * `App\Support\Money` enforces everywhere else money crosses a boundary.
 *
 * `isEstimate` is true only when `amount` came from `carriers
 * .base_delivery_price` rather than a live vendor quote — `CalculateDeliveryPrice`
 * sets it when the gateway threw `CourierUnavailableException`. The checkout
 * page reads it to show "estimated" instead of a figure that looks final.
 */
final readonly class DeliveryQuote
{
    public function __construct(
        public string $amount,
        public string $currency,
        public ?int $estimatedDays = null,
        public bool $isEstimate = false,
    ) {}
}
