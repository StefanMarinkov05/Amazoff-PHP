<?php

declare(strict_types=1);

use App\Enums\ShipmentStatus;
use App\Exceptions\CourierUnavailableException;
use App\Http\Integrations\Econt\EcontConnector;
use App\Http\Integrations\Econt\EcontGateway;
use App\Http\Integrations\Econt\Requests\CalculateShipmentRequest;
use App\Http\Integrations\Econt\Requests\CreateShipmentLabelRequest;
use App\Http\Integrations\Econt\Requests\GetOfficesRequest;
use App\Http\Integrations\Econt\Requests\GetShipmentStatusesRequest;
use App\Http\Integrations\Econt\Requests\SearchCitiesRequest;
use App\Support\Courier\ShipmentRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/*
 * What is ours here: mapping Econt's documented JSON shape onto this app's
 * DTOs, and — the trap this gateway exists to guard against — treating a
 * 200 response carrying an `error` key as a failure rather than data.
 * Endpoint paths and field names come from ee.econt.com's published API,
 * not a confirmed sandbox response; see EcontGateway's own class docblock.
 */

function econtGateway(MockClient $mockClient): EcontGateway
{
    $connector = (new EcontConnector)->withMockClient($mockClient);

    return new EcontGateway($connector);
}

function fakeShipmentRequest(): ShipmentRequest
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

it('maps a city search response onto CourierCity', function (): void {
    $mock = new MockClient([
        SearchCitiesRequest::class => MockResponse::make([
            'cities' => [
                ['id' => 41, 'name' => 'София', 'postCode' => '1000'],
            ],
        ]),
    ]);

    $cities = econtGateway($mock)->cities('София');

    expect($cities)->toHaveCount(1)
        ->and($cities->first()->vendorId)->toBe('41')
        ->and($cities->first()->postcode)->toBe('1000');
});

it('maps an office search response onto CourierOffice', function (): void {
    $mock = new MockClient([
        GetOfficesRequest::class => MockResponse::make([
            'offices' => [
                [
                    'code' => 'SO1',
                    'name' => 'Sofia Office 1',
                    'address' => ['fullAddress' => 'Vitosha Blvd 1', 'city' => ['name' => 'Sofia', 'postCode' => '1000']],
                    'maxParcelWeight' => 20,
                ],
            ],
        ]),
    ]);

    $offices = econtGateway($mock)->offices('Sofia');

    expect($offices)->toHaveCount(1)
        ->and($offices->first()->code)->toBe('SO1')
        ->and($offices->first()->maxWeightGrams)->toBe(20000);
});

it('maps a calculate response onto a DeliveryQuote', function (): void {
    $mock = new MockClient([
        CalculateShipmentRequest::class => MockResponse::make([
            'label' => ['totalPrice' => 6.5, 'currency' => 'BGN'],
        ]),
    ]);

    $quote = econtGateway($mock)->quote(fakeShipmentRequest());

    expect($quote->amount)->toBe('6.50')
        ->and($quote->currency)->toBe('BGN');
});

it('maps a create-label response onto a ShipmentResult', function (): void {
    $mock = new MockClient([
        CreateShipmentLabelRequest::class => MockResponse::make([
            'label' => ['shipmentNumber' => 'BG123456789', 'pdfURL' => 'https://example.test/label.pdf'],
        ]),
    ]);

    $result = econtGateway($mock)->createShipment(fakeShipmentRequest());

    expect($result->shipmentNumber)->toBe('BG123456789')
        ->and($result->labelUrl)->toBe('https://example.test/label.pdf');
});

it('maps tracking events onto ShipmentStatus', function (): void {
    $mock = new MockClient([
        GetShipmentStatusesRequest::class => MockResponse::make([
            'shipmentStatuses' => [
                [
                    'events' => [
                        ['status' => 'Delivered', 'time' => '2026-01-01T10:00:00+02:00', 'description' => 'Handed to recipient'],
                    ],
                ],
            ],
        ]),
    ]);

    $events = econtGateway($mock)->track('BG123456789');

    expect($events)->toHaveCount(1)
        ->and($events->first()->status)->toBe(ShipmentStatus::Delivered);
});

it('treats an HTTP 200 response carrying an error key as a failure', function (): void {
    // Econt's demo API is documented to return 200 with the failure
    // described in the body — the trap this gateway exists to guard
    // against. A naive $response->failed() check alone would let this
    // through as a valid, empty city list.
    $mock = new MockClient([
        SearchCitiesRequest::class => MockResponse::make(['error' => 'Invalid countryCode'], 200),
    ]);

    expect(fn () => econtGateway($mock)->cities('Nowhere'))
        ->toThrow(CourierUnavailableException::class);
});

it('wraps a genuine HTTP failure as CourierUnavailableException', function (): void {
    $mock = new MockClient([
        SearchCitiesRequest::class => MockResponse::make([], 503),
    ]);

    expect(fn () => econtGateway($mock)->cities('Sofia'))
        ->toThrow(CourierUnavailableException::class);
});

it('degrades to CourierUnavailableException rather than crashing when a 200 response does not match the assumed shape', function (): void {
    // Regression: a live checkout once 500'd because a real Econt response
    // did not match this gateway's assumed field shape — mapping code that
    // runs *after* a successful HTTP response threw, and that was outside
    // send()'s try/catch, which only guards the HTTP call itself.
    // guarded() wraps the whole method body specifically so this degrades
    // instead of crashing. An unparseable timestamp is a realistic way for
    // a vendor's actual format to differ from what was assumed.
    $mock = new MockClient([
        GetShipmentStatusesRequest::class => MockResponse::make([
            'shipmentStatuses' => [
                ['events' => [['status' => 'Delivered', 'time' => 'not-a-real-timestamp']]],
            ],
        ]),
    ]);

    expect(fn () => econtGateway($mock)->track('BG123456789'))
        ->toThrow(CourierUnavailableException::class);
});
