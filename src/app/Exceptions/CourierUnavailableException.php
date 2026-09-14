<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The only exception a `CourierGateway` implementation may let escape.
 * Every vendor failure — a network timeout, an HTTP error, a 200 response
 * carrying an error payload (Econt's API does this; see `EcontGateway`) — is
 * caught at the gateway boundary and rethrown as this, so nothing above it
 * ever needs to know Saloon or a vendor's own exception types exist.
 *
 * `extends RuntimeException` deliberately: `CheckoutPage::placeOrder()`
 * already catches `RuntimeException` and turns it into a form error rather
 * than a 500 (see its class docblock), and `CalculateDeliveryPrice` catches
 * this one specifically to fall back to `carriers.base_delivery_price`.
 */
class CourierUnavailableException extends RuntimeException
{
    public static function requestFailed(string $carrierCode, string $operation, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('%s is temporarily unavailable (%s).', ucfirst($carrierCode), $operation),
            previous: $previous,
        );
    }
}
