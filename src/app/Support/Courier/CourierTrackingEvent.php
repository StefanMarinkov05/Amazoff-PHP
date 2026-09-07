<?php

declare(strict_types=1);

namespace App\Support\Courier;

use App\Enums\ShipmentStatus;
use DateTimeImmutable;

/**
 * One vendor status update, already mapped onto `ShipmentStatus` by the
 * gateway that produced it — `rawStatus` is kept only for
 * `shipments.raw_status`, which exists for support and audit reading of a
 * vendor's own vocabulary, never for a second mapping downstream.
 */
final readonly class CourierTrackingEvent
{
    public function __construct(
        public string $rawStatus,
        public ShipmentStatus $status,
        public DateTimeImmutable $occurredAt,
        public ?string $description,
    ) {}
}
