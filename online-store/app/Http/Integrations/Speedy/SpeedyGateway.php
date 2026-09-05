<?php

declare(strict_types=1);

namespace App\Http\Integrations\Speedy;

use App\Contracts\CourierGateway;
use App\Enums\ShipmentStatus;
use App\Exceptions\CourierUnavailableException;
use App\Http\Integrations\Speedy\Requests\CalculatePriceRequest;
use App\Http\Integrations\Speedy\Requests\CreateShipmentRequest;
use App\Http\Integrations\Speedy\Requests\PrintLabelRequest;
use App\Http\Integrations\Speedy\Requests\SearchOfficesRequest;
use App\Http\Integrations\Speedy\Requests\SearchSitesRequest;
use App\Http\Integrations\Speedy\Requests\TrackShipmentRequest;
use App\Support\Courier\CourierCity;
use App\Support\Courier\CourierOffice;
use App\Support\Courier\CourierTrackingEvent;
use App\Support\Courier\DeliveryQuote;
use App\Support\Courier\ShipmentRequest;
use App\Support\Courier\ShipmentResult;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

/**
 * Translates Speedy's REST Web API JSON into this app's DTOs — the sibling
 * of `Econt\EcontGateway`, mapping a differently-shaped vendor API onto the
 * exact same `CourierGateway` contract.
 *
 * Same caveat as Econt's gateway: field names come from Speedy's published
 * API documentation, not a confirmed sandbox response — `SPEEDY_USERNAME`/
 * `SPEEDY_PASSWORD` are blank in `.env.example` pending the sandbox access
 * ADR-0001 flags as unresolved. Re-verify `mapStatus()` and the response
 * paths below against a real account before this reaches a customer.
 */
final class SpeedyGateway implements CourierGateway
{
    public function __construct(private readonly SpeedyConnector $connector) {}

    public function code(): string
    {
        return 'speedy';
    }

    public function cities(string $term): Collection
    {
        return $this->guarded('cities', function () use ($term): Collection {
            $data = $this->send(new SearchSitesRequest($term), 'cities');

            /** @var Collection<int, CourierCity> */
            return collect($data['sites'] ?? [])->map(static fn (mixed $site): CourierCity => new CourierCity(
                vendorId: (string) self::field($site, 'id'),
                name: (string) self::field($site, 'name'),
                postcode: (string) self::field($site, 'postCode', ''),
                country: 'BG',
            ));
        });
    }

    public function offices(string $city, ?string $postcode = null): Collection
    {
        return $this->guarded('offices', function () use ($city): Collection {
            $data = $this->send(new SearchOfficesRequest($city), 'offices');

            /** @var Collection<int, CourierOffice> */
            return collect($data['offices'] ?? [])->map(static function (mixed $office) use ($city): CourierOffice {
                $address = self::field($office, 'address', []);

                return new CourierOffice(
                    code: (string) self::field($office, 'id'),
                    name: (string) self::field($office, 'name'),
                    address: (string) self::field($address, 'fullAddressString', ''),
                    city: (string) (self::field($address, 'siteName') ?? $city),
                    postcode: (string) self::field($address, 'postCode', ''),
                    maxWeightGrams: self::field(self::field($office, 'maxParcelDimensions', []), 'weight') !== null
                        ? (int) round(((float) self::field(self::field($office, 'maxParcelDimensions', []), 'weight')) * 1000)
                        : null,
                );
            });
        });
    }

    public function quote(ShipmentRequest $request): DeliveryQuote
    {
        return $this->guarded('quote', function () use ($request): DeliveryQuote {
            $data = $this->send(new CalculatePriceRequest($request), 'quote');
            $calculation = $data['calculations'][0] ?? $data;
            $price = self::field($calculation, 'price', []);

            return new DeliveryQuote(
                amount: number_format((float) self::field($price, 'total', 0), 2, '.', ''),
                currency: (string) self::field($price, 'currency', 'BGN'),
            );
        });
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return $this->guarded('createShipment', function () use ($request): ShipmentResult {
            $data = $this->send(new CreateShipmentRequest($request), 'createShipment');
            $parcel = $data['parcels'][0] ?? [];
            $parcelId = self::field($parcel, 'id');
            $shipmentId = self::field($data, 'id');

            if ($shipmentId === null) {
                throw CourierUnavailableException::requestFailed($this->code(), 'createShipment');
            }

            return new ShipmentResult(
                shipmentNumber: (string) $shipmentId,
                trackingNumber: $parcelId !== null ? (string) $parcelId : null,
                labelUrl: null, // Speedy returns the label from a separate /print call — see label().
                trackingUrl: $parcelId !== null
                    ? sprintf('https://www.speedy.bg/en/track-shipment?parcelId=%s', $parcelId)
                    : null,
            );
        });
    }

    public function label(string $shipmentNumber): string
    {
        return $this->guarded('label', function () use ($shipmentNumber): string {
            $response = $this->rawSend(new PrintLabelRequest($shipmentNumber), 'label');

            if ($this->failedRequest($response)) {
                throw CourierUnavailableException::requestFailed($this->code(), 'label');
            }

            return $response->body();
        });
    }

    public function track(string $trackingNumber): Collection
    {
        return $this->guarded('track', function () use ($trackingNumber): Collection {
            $data = $this->send(new TrackShipmentRequest($trackingNumber), 'track');
            $parcel = $data['parcels'][0] ?? [];
            $operations = self::field($parcel, 'operations', []);

            /** @var Collection<int, CourierTrackingEvent> */
            return collect($operations)->map(function (mixed $operation): CourierTrackingEvent {
                $raw = (string) self::field($operation, 'type', '');

                return new CourierTrackingEvent(
                    rawStatus: $raw,
                    status: $this->mapStatus($raw),
                    occurredAt: new DateTimeImmutable((string) self::field($operation, 'dateTime', 'now')),
                    description: self::field($operation, 'description'),
                );
            });
        });
    }

    /**
     * Reads one key from a value that is *expected* to be an array shaped
     * like Speedy's documentation says — read defensively rather than
     * through direct array access, because that documentation is not
     * confirmed against a live payload (see this class's docblock).
     */
    private static function field(mixed $value, string $key, mixed $default = null): mixed
    {
        return is_array($value) ? ($value[$key] ?? $default) : $default;
    }

    /**
     * Wraps every public method's full body — the HTTP call *and* the
     * response mapping — so a vendor payload that does not match this
     * gateway's assumed shape degrades to `CourierUnavailableException`
     * instead of an uncaught `TypeError` reaching the customer as a 500.
     * See `EcontGateway::guarded()` for the identical reasoning.
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
        $response = $this->rawSend($request, $operation);

        if ($this->failedRequest($response)) {
            throw CourierUnavailableException::requestFailed($this->code(), $operation);
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /** @throws CourierUnavailableException */
    private function rawSend(Request $request, string $operation): Response
    {
        try {
            return $this->connector->send($request);
        } catch (Throwable $e) {
            throw CourierUnavailableException::requestFailed($this->code(), $operation, $e);
        }
    }

    /**
     * Speedy's documented error shape is an `error` object in an otherwise
     * 200 response, the same non-obvious failure mode Econt's API has —
     * see `EcontGateway::failedRequest()`.
     *
     * Gated on the request type rather than `$response->isJson()`: a
     * Content-Type sniff depends on the server (or a test's `MockResponse`)
     * setting the header correctly, and `PrintLabelRequest` is the only
     * request in this gateway that legitimately returns a non-JSON — a raw
     * PDF — body.
     */
    private function failedRequest(Response $response): bool
    {
        if ($response->failed()) {
            return true;
        }

        if ($response->getRequest() instanceof PrintLabelRequest) {
            return false;
        }

        $body = $response->json();

        return isset($body['error']);
    }

    private function mapStatus(string $raw): ShipmentStatus
    {
        return match (true) {
            str_contains($raw, 'DELIVERED') => ShipmentStatus::Delivered,
            str_contains($raw, 'RETURN') => ShipmentStatus::Returned,
            str_contains($raw, 'CANCEL') => ShipmentStatus::Cancelled,
            str_contains($raw, 'TRANSIT'), str_contains($raw, 'FORWARD') => ShipmentStatus::InTransit,
            str_contains($raw, 'PICKUP'), str_contains($raw, 'SENT') => ShipmentStatus::Shipped,
            default => ShipmentStatus::Pending,
        };
    }
}
