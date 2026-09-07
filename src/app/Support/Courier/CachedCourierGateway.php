<?php

declare(strict_types=1);

namespace App\Support\Courier;

use App\Contracts\CourierGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Wraps any `CourierGateway` and caches its read-only lookups. Written once
 * here rather than inside each gateway, so `EcontGateway`/`SpeedyGateway`
 * stay pure vendor-JSON-to-DTO mappers with nothing else to test.
 *
 * `createShipment()`, `label()` and `track()` pass straight through —
 * caching a write is a bug, and a tracking poll is meant to see the
 * courier's current state, not a stale one.
 *
 * This is also what makes an office picker viable against a real API: a
 * city's office list is fetched once per TTL and then filtered client-side
 * as the customer types, rather than firing a request per keystroke.
 *
 * ## Why this caches plain arrays, never the DTOs themselves
 *
 * `config('cache.serializable_classes')` is `false` — this project's own
 * deliberate default, "to prevent gadget chain attacks if your APP_KEY is
 * leaked" (`config/cache.php`). That makes every store built on PHP's
 * `unserialize()` (the `database` driver included) pass `allowed_classes:
 * false`, which silently turns *any* cached object back into a useless
 * `__PHP_Incomplete_Class` on the very next read — not an error at cache-write
 * time, only a `TypeError` later, on the first cache *hit*, exactly where a
 * caching layer is supposed to disappear. Caching a plain array of scalars
 * — this DTO's own public properties, via `(array) $dto` — sidesteps the
 * restriction entirely rather than asking the project to weaken its own
 * security default for one feature's convenience.
 */
final readonly class CachedCourierGateway implements CourierGateway
{
    public function __construct(private CourierGateway $inner) {}

    public function code(): string
    {
        return $this->inner->code();
    }

    public function cities(string $term): Collection
    {
        /** @var list<array{vendorId: string, name: string, postcode: string, country: string}> $rows */
        $rows = Cache::remember(
            $this->key('cities', $term),
            $this->ttl('couriers.cache.offices_ttl', 86400),
            fn (): array => $this->inner->cities($term)->map(static fn (CourierCity $city): array => (array) $city)->all(),
        );

        return collect($rows)->map(static fn (array $row): CourierCity => new CourierCity(...$row));
    }

    public function offices(string $city, ?string $postcode = null): Collection
    {
        /** @var list<array{code: string, name: string, address: string, city: string, postcode: string, maxWeightGrams: ?int, supportsCod: bool}> $rows */
        $rows = Cache::remember(
            $this->key('offices', $city, $postcode ?? ''),
            $this->ttl('couriers.cache.offices_ttl', 86400),
            fn (): array => $this->inner->offices($city, $postcode)->map(static fn (CourierOffice $office): array => (array) $office)->all(),
        );

        return collect($rows)->map(static fn (array $row): CourierOffice => new CourierOffice(...$row));
    }

    public function quote(ShipmentRequest $request): DeliveryQuote
    {
        $key = $this->key(
            'quote',
            $request->city,
            $request->postcode,
            $request->officeCode ?? '',
            (string) $request->weightGrams,
            $request->codAmount ?? '',
        );

        /** @var array{amount: string, currency: string, estimatedDays: ?int, isEstimate: bool} $row */
        $row = Cache::remember(
            $key,
            $this->ttl('couriers.cache.quote_ttl', 300),
            fn (): array => (array) $this->inner->quote($request),
        );

        return new DeliveryQuote(...$row);
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return $this->inner->createShipment($request);
    }

    public function label(string $shipmentNumber): string
    {
        return $this->inner->label($shipmentNumber);
    }

    public function track(string $trackingNumber): Collection
    {
        return $this->inner->track($trackingNumber);
    }

    private function key(string ...$parts): string
    {
        return sprintf('courier:%s:%s', $this->inner->code(), implode(':', $parts));
    }

    private function ttl(string $configKey, int $default): int
    {
        $value = config($configKey, $default);

        if (! is_int($value)) {
            throw new InvalidArgumentException("Config value [{$configKey}] must be an integer.");
        }

        return $value;
    }
}
