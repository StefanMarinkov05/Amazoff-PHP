<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Exceptions\CourierUnavailableException;
use App\Facades\Courier;
use App\Models\Carrier;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariation;
use App\Support\Courier\DeliveryQuote;
use App\Support\Courier\ShipmentRequest;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * The delivery price a checkout shows and `CreateOrder` charges — a
 * function, not an Action, per the reasoning `CalculateCartTotals`'s own
 * docblock already gives: it writes nothing, so ADR-0007's threshold for a
 * command class does not apply.
 *
 * Reads through the `Courier` facade rather than a constructor-injected
 * `CourierManager`, matching `docs/explanation/couriers.md`'s split: static
 * facade calls are for the read path (this and `CheckoutPage`), constructor
 * injection is for Actions with a write to make.
 *
 * On `CourierUnavailableException` this falls back to `carriers
 * .base_delivery_price` and marks the quote `isEstimate: true` rather than
 * failing checkout outright — an outage in a third-party pricing API is not
 * a reason to refuse an order.
 *
 * `cod_fee` is added here, after the vendor quote or its fallback, because
 * it is this app's own surcharge on top of the courier's price, not
 * something either vendor's `quote()` call already knows to include.
 */
final class CalculateDeliveryPrice
{
    /**
     * @param  array<string, mixed>  $deliveryAddress  Same shape `CreateOrder`
     *                                                 receives: delivery_type, first_name, last_name, phone, country, city,
     *                                                 postcode, plus street or courier_office_code.
     */
    public static function forCart(
        Cart $cart,
        Carrier $carrier,
        PaymentMethod $paymentMethod,
        array $deliveryAddress,
    ): DeliveryQuote {
        $request = new ShipmentRequest(
            city: (string) self::optionalStringField($deliveryAddress, 'city'),
            postcode: (string) self::optionalStringField($deliveryAddress, 'postcode'),
            country: self::optionalStringField($deliveryAddress, 'country') ?? 'BG',
            street: self::optionalStringField($deliveryAddress, 'street'),
            officeCode: self::optionalStringField($deliveryAddress, 'courier_office_code'),
            receiverName: trim(sprintf(
                '%s %s',
                self::optionalStringField($deliveryAddress, 'first_name') ?? '',
                self::optionalStringField($deliveryAddress, 'last_name') ?? '',
            )),
            receiverPhone: self::optionalStringField($deliveryAddress, 'phone') ?? '',
            weightGrams: self::totalWeightGrams($cart),
            codAmount: $paymentMethod === PaymentMethod::CashOnDelivery ? '0.00' : null,
        );

        try {
            $quote = Courier::for($carrier)->quote($request);
        } catch (CourierUnavailableException) {
            $quote = new DeliveryQuote(
                amount: (string) $carrier->base_delivery_price,
                currency: 'BGN',
                isEstimate: true,
            );
        }

        $amount = Money::of($quote->amount);

        if ($paymentMethod === PaymentMethod::CashOnDelivery) {
            $amount = $amount->add(Money::of((string) $carrier->cod_fee));
        }

        return new DeliveryQuote(
            amount: (string) $amount,
            currency: $quote->currency,
            estimatedDays: $quote->estimatedDays,
            isEstimate: $quote->isEstimate,
        );
    }

    /**
     * Reads a `$deliveryAddress` field as a string, tolerating an absent or
     * null key the same way the original `(string) ($array[$key] ?? null)`
     * casts did — this is form input, not a place to throw on a shape the
     * caller may legitimately omit (street vs. courier_office_code).
     *
     * @param  array<string, mixed>  $deliveryAddress
     */
    private static function optionalStringField(array $deliveryAddress, string $key): ?string
    {
        $value = $deliveryAddress[$key] ?? null;

        return $value === null ? null : (string) (is_scalar($value) ? $value : json_encode($value));
    }

    /**
     * Sums each line's resolved weight × quantity, falling back to
     * `couriers.default_parcel_weight_grams` per line for a variation with
     * no recorded weight — `ResolveVariationMeasurements::weightGrams()`
     * returns null there, and a courier quote needs a number regardless.
     */
    private static function totalWeightGrams(Cart $cart): int
    {
        $fallback = config('couriers.default_parcel_weight_grams', 500);

        if (! is_int($fallback)) {
            throw new InvalidArgumentException('Config value [couriers.default_parcel_weight_grams] must be an integer.');
        }

        /** @var Collection<int, CartItem> $items */
        $items = $cart->cartItems()->with('productVariation.product')->get();

        $totalGrams = 0;

        foreach ($items as $item) {
            /** @var ProductVariation|null $variation */
            $variation = $item->productVariation;

            $weight = $variation === null
                ? $fallback
                : ResolveVariationMeasurements::weightGrams($variation) ?? $fallback;

            $totalGrams += $weight * $item->quantity;
        }

        return $totalGrams;
    }
}
