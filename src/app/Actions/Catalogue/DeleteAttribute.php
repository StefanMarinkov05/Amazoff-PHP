<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Exceptions\AttributeCannotBeDeletedException;
use App\Models\Attribute;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes an attribute, refusing while it still has values.
 *
 * `attribute_values.attribute_id`'s foreign key already blocks this at the
 * database as error 1451, a raw `QueryException`. This Action turns that
 * into a message an administrator can act on, thrown from inside a lock
 * rather than left to the database: a value created for this attribute in
 * the same instant is either already visible to the count or is itself
 * blocked waiting on the lock — see `explanation/concurrency-and-locking.md`.
 *
 * Authorizes `delete_attribute`. Locks `attributes`.
 */
final class DeleteAttribute
{
    /**
     * @throws AttributeCannotBeDeletedException
     */
    public function handle(Attribute $attribute, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $attribute);
        }

        DB::transaction(function () use ($attribute): void {
            /** @var Attribute $locked */
            $locked = Attribute::query()->lockForUpdate()->findOrFail($attribute->getKey());

            $values = $locked->attributeValues()->count();

            if ($values > 0) {
                throw AttributeCannotBeDeletedException::hasValues($locked, $values);
            }

            $locked->delete();
        });
    }
}
