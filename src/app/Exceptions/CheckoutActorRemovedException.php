<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * The authenticated actor was hard-deleted between being read and
 * `CreateOrder` inserting `orders.user_id`.
 *
 * `orders.user_id` is `nullOnDelete()`, which governs an *existing* child row
 * when its parent disappears — it does not stop a *new* insert from
 * referencing an id that is already gone, and that insert would otherwise
 * surface as an uncaught `QueryException`. `User` defaults to soft deletes
 * (see `attributes the order to the actor even if that account was
 * soft-deleted moments earlier` in `CreateOrderTest`), so reaching this needs
 * a hard delete specifically — narrow, not impossible.
 */
class CheckoutActorRemovedException extends RuntimeException
{
    public function __construct(
        public readonly User $actor,
    ) {
        $actorKey = $actor->getKey();

        if (! is_scalar($actorKey)) {
            throw new InvalidArgumentException('User::getKey() returned a non-scalar value.');
        }

        parent::__construct(sprintf(
            'The checking-out account (id %s) no longer exists.',
            $actorKey,
        ));
    }
}
