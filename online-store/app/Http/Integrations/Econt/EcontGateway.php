<?php

declare(strict_types=1);

namespace App\Http\Integrations\Econt;

use App\Contracts\CourierGateway;
use App\Enums\ShipmentStatus;
use App\Exceptions\CourierUnavailableException;
use App\Http\Integrations\Econt\Requests\CalculateShipmentRequest;
use App\Http\Integrations\Econt\Requests\CreateShipmentLabelRequest;
use App\Http\Integrations\Econt\Requests\GetOfficesRequest;
use App\Http\Integrations\Econt\Requests\GetShipmentStatusesRequest;
use App\Http\Integrations\Econt\Requests\SearchCitiesRequest;
use App\Support\Courier\CourierCity;
use App\Support\Courier\CourierOffice;
use App\Support\Courier\CourierTrackingEvent;
use App\Support\Courier\DeliveryQuote;
use App\Support\Courier\ShipmentRequest;
use App\Support\Courier\ShipmentResult;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

/**
 * Translates Econt's `Nomenclatures`/`Shipments` JSON into this app's DTOs.
 * Every method is call → map, nothing else: no caching (that's
 * `CachedCourierGateway`'s job) and no Eloquent.
 *
 * Econt's own status vocabulary (`shortDeliveryStatusEn` and similar) is not
 * settled against a real sandbox response as of this writing —
 * `mapStatus()`'s cases are the vendor's documented labels, not values
 * confirmed from a live payload. Re-verify against `ee.econt.com/services`
 * before this reaches a real customer, the same caveat `CarrierSeeder`
 * already carries for the tariff figures.
 */
final class EcontGateway implements CourierGateway
{
    public function __construct(private readonly EcontConnector $connector) {}

    public function code(): string
    {
        return 'econt';
    }

    public function cities(string $term): Collection
    {
        return $this->guarded('cities', function () use ($term): Collection {
            $data = $this->send(new SearchCitiesRequest($term), 'cities');

            /** @var Collection<int, CourierCity> */
            return collect($data['cities'] ?? [])->map(static fn (mixed $city): CourierCity => new CourierCity(
                vendorId: (string) self::field($city, 'id'),
                name: (string) self::field($city, 'name'),
                postcode: (string) self::field($city, 'postCode', ''),
                country: 'BG',
            ));
        });
    }

    public function offices(string $city, ?string $postcode = null): Collection
    {
        return $this->guarded('offices', function () use ($city, $postcode): Collection {
            $data = $this->send(new GetOfficesRequest($city, $postcode), 'offices');

            /** @var Collection<int, CourierOffice> */
            return collect($data['offices'] ?? [])->map(static function (mixed $office) use ($city): CourierOffice {
                $address = self::field($office, 'address', []);

                return new CourierOffice(
                    code: (string) self::field($office, 'code'),
                    name: (string) self::field($office, 'name'),
                    address: (string) (self::field($address, 'fullAddress') ?? self::field($address, 'street', '')),
                    city: (string) (self::field(self::field($address, 'city', []), 'name') ?? $city),
                    postcode: (string) self::field(self::field($address, 'city', []), 'postCode', ''),
                    maxWeightGrams: self::field($office, 'maxParcelWeight') !== null
                        ? (int) round(((float) self::field($office, 'maxParcelWeight')) * 1000)
                        : null,
                    supportsCod: (bool) self::field($office, 'currency', true),
                );
            });
        });
    }

    public function quote(ShipmentRequest $request): DeliveryQuote
    {
        return $this->guarded('quote', function () use ($request): DeliveryQuote {
            $data = $this->send(new CalculateShipmentRequest($request), 'quote');
            $label = $data['label'] ?? $data;

            return new DeliveryQuote(
                amount: number_format((float) (self::field($label, 'totalPrice', 0)), 2, '.', ''),
                currency: (string) self::field($label, 'currency', 'BGN'),
            );
        });
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return $this->guarded('createShipment', function () use ($request): ShipmentResult {
            $data = $this->send(new CreateShipmentLabelRequest($request), 'createShipment');
            $label = $data['label'] ?? $data;
            $shipmentNumber = self::field($label, 'shipmentNumber');

            if ($shipmentNumber === null) {
                throw CourierUnavailableException::requestFailed($this->code(), 'createShipment');
            }

            return new ShipmentResult(
                shipmentNumber: (string) $shipmentNumber,
                trackingNumber: (string) $shipmentNumber,
                labelUrl: self::field($label, 'pdfURL') !== null ? (string) self::field($label, 'pdfURL') : null,
                trackingUrl: sprintf('https://www.econt.com/services/track-shipment/%s', $shipmentNumber),
            );
        });
    }

    public function label(string $shipmentNumber): string
    {
        return $this->guarded('label', function () use ($shipmentNumber): string {
            $data = $this->send(new GetShipmentStatusesRequest($shipmentNumber), 'label');
            $status = $data['shipmentStatuses'][0] ?? $data['statuses'][0] ?? null;
            $pdfUrl = self::field($status, 'pdfURL');

            if (! is_string($pdfUrl) || $pdfUrl === '') {
                throw CourierUnavailableException::requestFailed($this->code(), 'label');
            }

            $response = Http::timeout((int) config('couriers.timeout', 10))->get($pdfUrl);

            if ($response->failed()) {
                throw CourierUnavailableException::requestFailed($this->code(), 'label');
            }

            return $response->body();
        });
    }

    public function track(string $trackingNumber): Collection
    {
        return $this->guarded('track', function () use ($trackingNumber): Collection {
            $data = $this->send(new GetShipmentStatusesRequest($trackingNumber), 'track');
            $status = $data['shipmentStatuses'][0] ?? $data['statuses'][0] ?? [];
            $events = self::field($status, 'events') ?? self::field($status, 'history', []);

            /** @var Collection<int, CourierTrackingEvent> */
            return collect($events)->map(function (mixed $event): CourierTrackingEvent {
                $raw = (string) (self::field($event, 'status') ?? self::field($event, 'shortDeliveryStatusEn', ''));

                return new CourierTrackingEvent(
                    rawStatus: $raw,
                    status: $this->mapStatus($raw),
                    occurredAt: new DateTimeImmutable((string) self::field($event, 'time', 'now')),
                    description: self::field($event, 'description'),
                );
            });
        });
    }

    /**
     * Reads one key from a value that is *expected* to be an array shaped
     * like the vendor's documentation says — but is read defensively rather
     * than through direct array access, because that documentation is not
     * confirmed against a live payload (see this class's docblock). Any
     * shape this returns the "wrong" thing for surfaces as a bad DTO field,
     * not a crash; `guarded()` is the backstop for the cases even this
     * can't make safe (a shipment number in the wrong type entirely, say).
     */
    private static function field(mixed $value, string $key, mixed $default = null): mixed
    {
        return is_array($value) ? ($value[$key] ?? $default) : $default;
    }

    /**
     * Wraps every public method's full body — the HTTP call *and* the
     * response mapping — so a vendor payload that does not match this
     * gateway's assumed shape degrades to `CourierUnavailableException`
     * (which `CalculateDeliveryPrice` and `CheckoutPage` already handle)
     * instead of an uncaught `TypeError` reaching the customer as a 500.
     * `send()` alone only guarded the network/HTTP half; the mapping code
     * that runs after a *successful* response was the gap this closes.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     *
     * @throws CourierUnavailableException
     */
    private function guarded(string $operation, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (CourierUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw CourierUnavailableException::requestFailed($this->code(), $operation, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CourierUnavailableException
     */
    private function send(Request $request, string $operation): array
    {
        try {
            $response = $this->connector->send($request);
        } catch (Throwable $e) {
            throw CourierUnavailableException::requestFailed($this->code(), $operation, $e);
        }

        if ($this->failedRequest($response)) {
            throw CourierUnavailableException::requestFailed($this->code(), $operation);
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Econt's demo API is documented to return HTTP 200 with the failure
     * described in the JSON body rather than a 4xx/5xx status, so an HTTP
     * success is not enough — every response is also checked for an
     * `error`/`errors` key before its data is trusted.
     */
    private function failedRequest(Response $response): bool
    {
        if ($response->failed()) {
            return true;
        }

        $body = $response->json();

        return isset($body['error']) || isset($body['errors']);
    }

    private function mapStatus(string $raw): ShipmentStatus
    {
        return match (true) {
            str_contains($raw, 'Delivered') => ShipmentStatus::Delivered,
            str_contains($raw, 'Returned') => ShipmentStatus::Returned,
            str_contains($raw, 'Cancel') => ShipmentStatus::Cancelled,
            str_contains($raw, 'Transit'), str_contains($raw, 'Forwarded') => ShipmentStatus::InTransit,
            str_contains($raw, 'Sent'), str_contains($raw, 'Shipped') => ShipmentStatus::Shipped,
            default => ShipmentStatus::Pending,
        };
    }
}
