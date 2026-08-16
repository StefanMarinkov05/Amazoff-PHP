<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * A product was about to exist, or become sellable, with no variation.
 *
 * §6–7: every sellable product has at least one variation, including products
 * with nothing to vary, because stock hangs off the variation rather than the
 * product. A product with none has nowhere to hold a quantity, so it cannot be
 * added to a cart, reserved, or sold — the catalogue would show a row the rest
 * of the system has no way to fulfil.
 *
 * A domain exception rather than a false return, per ADR-0007. The three named
 * constructors are the three doors the invariant can be broken through, and
 * the wording differs because each is raised on a different screen — "add a
 * variation first" is useless advice on a create page and the right advice on
 * an edit page.
 *
 * Guarding only some of the doors does not guard the invariant. `atCreation`
 * and `whenMadeAvailable` were built first, which left removal open: deleting
 * the only variation of an available product needed no concurrency at all and
 * nothing objected.
 */
class ProductRequiresVariationException extends RuntimeException
{
    public function __construct(string $message, public readonly ?Product $product = null)
    {
        parent::__construct($message);
    }

    public static function atCreation(): self
    {
        return new self('A product cannot be created without at least one variation.');
    }

    public static function whenMadeAvailable(Product $product): self
    {
        return new self(
            sprintf('Product %s cannot be made available: it has no variation.', $product->sku),
            $product,
        );
    }

    public static function whenLastVariationRemoved(Product $product): self
    {
        return new self(
            sprintf(
                'Cannot remove the last variation of product %s while it is available. '
                .'Make the product unavailable first.',
                $product->sku,
            ),
            $product,
        );
    }
}
