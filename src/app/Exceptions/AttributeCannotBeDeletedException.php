<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Attribute;
use RuntimeException;

/**
 * An attribute was refused deletion because it still has values.
 *
 * `attribute_values.attribute_id` is a `constrained()` foreign key with no
 * cascade, so the database already refuses to delete an attribute any
 * value still points at — as error 1451, a raw `QueryException`. This
 * exception is what turns that into a message an administrator can act on,
 * thrown from inside a lock rather than left to the database:
 * `DeleteAttribute` re-reads the live value count under `lockForUpdate()`
 * before deciding, so a value created for it in the same instant cannot
 * slip past a stale count.
 */
class AttributeCannotBeDeletedException extends RuntimeException
{
    public function __construct(string $message, public readonly Attribute $attribute)
    {
        parent::__construct($message);
    }

    public static function hasValues(Attribute $attribute, int $values): self
    {
        return new self(sprintf(
            'Attribute %s has %d value(s) and cannot be deleted.',
            $attribute->name,
            $values,
        ), $attribute);
    }
}
