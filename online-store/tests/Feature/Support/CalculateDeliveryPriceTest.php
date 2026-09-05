<?php

declare(strict_types=1);

use App\Actions\Cart\AddToCart;
use App\Contracts\CourierGateway;
use App\Enums\PaymentMethod;
use App\Exceptions\CourierUnavailableException;
use App\Facades\Courier;
use App\Models\Carrier;
use App\Support\CalculateDeliveryPrice;
use App\Support\Courier\DeliveryQuote;
use App\Support\Courier\ShipmentRequest;
use App\Support\Courier\ShipmentResult;
use Illuminate\Support\Collection;

/*
 * What is ours here: the fallback to carriers.base_delivery_price on a
 * courier outage, and adding cod_fee on top of the vendor quote only for
 * cash on delivery. The quote/mapping itself is EcontGatewayTest's and
 * SpeedyGatewayTest's territory.
 *
 * cartVariation(), emptyCart(), swapFakeCourier() and checkoutCarrier() come
 * from tests/Pest.php.
 */

/** @return array<string, mixed> */
function deliveryAddress(array $overrides = []): array
{
    return array_merge([
        'delivery_type' => 'address',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'phone' => '+359888123456',
        'country' => 'BG',
        'city' => 'Sofia',
        'postcode' => '1000',
        'street' => 'Vitosha 1',
        'courier_office_code' => null,
    ], $overrides);
}

it('charges the vendor quote for a card order, with no cod fee added', function (): void {
    swapFakeCourier()->fakeQuoteAmount = '4.20';
    $carrier = checkoutCarrier(['cod_fee' => '9.99']);

    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $quote = CalculateDeliveryPrice::forCart($cart, $carrier, PaymentMethod::Stripe, deliveryAddress());

    expect($quote->amount)->toBe('4.20')
        ->and($quote->isEstimate)->toBeFalse();
});

it('adds the carrier cash-on-delivery fee on top of the vendor quote', function (): void {
    swapFakeCourier()->fakeQuoteAmount = '4.20';
    $carrier = checkoutCarrier(['cod_fee' => '1.30']);

    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $quote = CalculateDeliveryPrice::forCart($cart, $carrier, PaymentMethod::CashOnDelivery, deliveryAddress());

    expect($quote->amount)->toBe('5.50');
});

it('falls back to base_delivery_price and marks the quote an estimate when the courier is unreachable', function (): void {
    $carrier = checkoutCarrier(['base_delivery_price' => '8.00', 'cod_fee' => '0.00']);

    Courier::swap(new class
    {
        public function for(Carrier $carrier): CourierGateway
        {
            return new class implements CourierGateway
            {
                public function code(): string
                {
                    return 'fake';
                }

                public function cities(string $term): Collection
                {
                    return collect();
                }

                public function offices(string $city, ?string $postcode = null): Collection
                {
                    return collect();
                }

                public function quote(ShipmentRequest $request): DeliveryQuote
                {
                    throw CourierUnavailableException::requestFailed('fake', 'quote');
                }

                public function createShipment(ShipmentRequest $request): ShipmentResult
                {
                    throw CourierUnavailableException::requestFailed('fake', 'createShipment');
                }

                public function label(string $shipmentNumber): string
                {
                    throw CourierUnavailableException::requestFailed('fake', 'label');
                }

                public function track(string $trackingNumber): Collection
                {
                    throw CourierUnavailableException::requestFailed('fake', 'track');
                }
            };
        }
    });

    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(), 1);

    $quote = CalculateDeliveryPrice::forCart($cart, $carrier, PaymentMethod::Stripe, deliveryAddress());

    expect($quote->amount)->toBe('8.00')
        ->and($quote->isEstimate)->toBeTrue();
});

it('sums weight across every cart line, falling back per line for a variation with no recorded weight', function (): void {
    $carrier = checkoutCarrier();

    $spy = new class implements CourierGateway
    {
        public ?int $lastWeightGrams = null;

        public function code(): string
        {
            return 'fake';
        }

        public function cities(string $term): Collection
        {
            return collect();
        }

        public function offices(string $city, ?string $postcode = null): Collection
        {
            return collect();
        }

        public function quote(ShipmentRequest $request): DeliveryQuote
        {
            $this->lastWeightGrams = $request->weightGrams;

            return new DeliveryQuote(amount: '1.00', currency: 'BGN');
        }

        public function createShipment(ShipmentRequest $request): ShipmentResult
        {
            throw new RuntimeException('not used');
        }

        public function label(string $shipmentNumber): string
        {
            throw new RuntimeException('not used');
        }

        public function track(string $trackingNumber): Collection
        {
            throw new RuntimeException('not used');
        }
    };

    Courier::swap(new class($spy)
    {
        public function __construct(private readonly CourierGateway $gateway) {}

        public function for(Carrier $carrier): CourierGateway
        {
            return $this->gateway;
        }
    });

    $cart = emptyCart();
    app(AddToCart::class)->handle($cart, cartVariation(variation: ['weight_g' => 300]), 2);
    app(AddToCart::class)->handle($cart, cartVariation(variation: ['weight_g' => null], product: ['weight_g' => null]), 1);

    CalculateDeliveryPrice::forCart($cart, $carrier, PaymentMethod::Stripe, deliveryAddress());

    // 300 * 2 + config('couriers.default_parcel_weight_grams', 500) * 1.
    expect($spy->lastWeightGrams)->toBe(300 * 2 + (int) config('couriers.default_parcel_weight_grams', 500));
});
