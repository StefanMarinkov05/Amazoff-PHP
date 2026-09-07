<?php

declare(strict_types=1);

use App\Enums\ShipmentStatus;
use App\Exceptions\CourierUnavailableException;
use App\Http\Integrations\Speedy\Requests\CalculatePriceRequest;
use App\Http\Integrations\Speedy\Requests\CreateShipmentRequest;
use App\Http\Integrations\Speedy\Requests\PrintLabelRequest;
use App\Http\Integrations\Speedy\Requests\SearchOfficesRequest;
use App\Http\Integrations\Speedy\Requests\SearchSitesRequest;
use App\Http\Integrations\Speedy\Requests\TrackShipmentRequest;
use App\Http\Integrations\Speedy\SpeedyConnector;
use App\Http\Integrations\Speedy\SpeedyGateway;
use App\Support\Courier\ShipmentRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/*
 * The Speedy sibling of EcontGatewayTest — same shared caveat: field names
 * come from Speedy's published REST API documentation, not a confirmed
 * sandbox response (no public Speedy sandbox exists; see SpeedyGateway's
 * class docblock). What is ours here is the same as Econt's: mapping onto
 * this app's DTOs, and treating a 200-with-error-body as a failure.
 */

function speedyGateway(MockClient $mockClient): SpeedyGateway
{
    $connector = (new SpeedyConnector)->withMockClient($mockClient);

    return new SpeedyGateway($connector);
}

function fakeSpeedyShipmentRequest(): ShipmentRequest
{
    return new ShipmentRequest(
        city: 'Sofia',
        postcode: '1000',
        country: 'BG',
        street: 'Vitosha 1',
        officeCode: null,
        receiverName: 'Ada Lovelace',
        receiverPhone: '+359888123456',
        weightGrams: 1200,
        codAmount: null,
    );
}

it('maps a site search response onto CourierCity', function (): void {
    $mock = new MockClient([
        SearchSitesRequest::class => MockResponse::make([
            'sites' => [
                ['id' => 68134, 'name' => 'София', 'postCode' => '1000'],
            ],
        ]),
    ]);

    $cities = speedyGateway($mock)->cities('София');

    expect($cities)->toHaveCount(1)
        ->and($cities->first()->vendorId)->toBe('68134');
});

it('maps an office search response onto CourierOffice', function (): void {
    $mock = new MockClient([
        SearchOfficesRequest::class => MockResponse::make([
            'offices' => [
                [
                    'id' => 3020,
                    'name' => 'Sofia Office 1',
                    'address' => ['fullAddressString' => 'Vitosha Blvd 1', 'siteName' => 'Sofia', 'postCode' => '1000'],
                    'maxParcelDimensions' => ['weight' => 20],
                ],
            ],
        ]),
    ]);

    $offices = speedyGateway($mock)->offices('Sofia');

    expect($offices)->toHaveCount(1)
        ->and($offices->first()->code)->toBe('3020')
        ->and($offices->first()->maxWeightGrams)->toBe(20000);
});

it('maps a calculate response onto a DeliveryQuote', function (): void {
    $mock = new MockClient([
        CalculatePriceRequest::class => MockResponse::make([
            'calculations' => [
                ['price' => ['total' => 7.2, 'currency' => 'BGN']],
            ],
        ]),
    ]);

    $quote = speedyGateway($mock)->quote(fakeSpeedyShipmentRequest());

    expect($quote->amount)->toBe('7.20')
        ->and($quote->currency)->toBe('BGN');
});

it('maps a create-shipment response onto a ShipmentResult', function (): void {
    $mock = new MockClient([
        CreateShipmentRequest::class => MockResponse::make([
            'id' => 'SPD-9988',
            'parcels' => [['id' => 'PARCEL-1']],
        ]),
    ]);

    $result = speedyGateway($mock)->createShipment(fakeSpeedyShipmentRequest());

    expect($result->shipmentNumber)->toBe('SPD-9988')
        ->and($result->trackingNumber)->toBe('PARCEL-1');
});

it('maps tracking operations onto ShipmentStatus', function (): void {
    $mock = new MockClient([
        TrackShipmentRequest::class => MockResponse::make([
            'parcels' => [
                [
                    'operations' => [
                        ['type' => 'DELIVERED', 'dateTime' => '2026-01-01T10:00:00+02:00', 'description' => 'Delivered'],
                    ],
                ],
            ],
        ]),
    ]);

    $events = speedyGateway($mock)->track('PARCEL-1');

    expect($events)->toHaveCount(1)
        ->and($events->first()->status)->toBe(ShipmentStatus::Delivered);
});

it('treats a 200 response carrying an error object as a failure', function (): void {
    $mock = new MockClient([
        SearchSitesRequest::class => MockResponse::make(['error' => ['message' => 'Invalid countryId']], 200),
    ]);

    expect(fn () => speedyGateway($mock)->cities('Nowhere'))
        ->toThrow(CourierUnavailableException::class);
});

it('does not treat a raw PDF label response as a failure', function (): void {
    $mock = new MockClient([
        PrintLabelRequest::class => MockResponse::make('%PDF-1.4 raw bytes', 200, ['Content-Type' => 'application/pdf']),
    ]);

    expect(speedyGateway($mock)->label('PARCEL-1'))->toBe('%PDF-1.4 raw bytes');
});

it('wraps a genuine HTTP failure as CourierUnavailableException', function (): void {
    $mock = new MockClient([
        SearchSitesRequest::class => MockResponse::make([], 503),
    ]);

    expect(fn () => speedyGateway($mock)->cities('Sofia'))
        ->toThrow(CourierUnavailableException::class);
});

it('degrades to CourierUnavailableException rather than crashing when a 200 response does not match the assumed shape', function (): void {
    // Same regression EcontGatewayTest pins: mapping code that runs after a
    // successful HTTP response was previously unguarded, so a vendor
    // payload that doesn't match this gateway's assumed shape threw a raw,
    // uncaught exception instead of degrading. guarded() closes that gap.
    $mock = new MockClient([
        TrackShipmentRequest::class => MockResponse::make([
            'parcels' => [
                ['operations' => [['type' => 'DELIVERED', 'dateTime' => 'not-a-real-timestamp']]],
            ],
        ]),
    ]);

    expect(fn () => speedyGateway($mock)->track('PARCEL-1'))
        ->toThrow(CourierUnavailableException::class);
});
