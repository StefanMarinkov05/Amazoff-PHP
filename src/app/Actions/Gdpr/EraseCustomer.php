<?php

declare(strict_types=1);

namespace App\Actions\Gdpr;

use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * GDPR Art. 17 erasure for one customer (ADR-0019).
 *
 * Anonymises what a legal obligation forces the shop to keep — the order,
 * as an invoice retained under Bulgarian accounting law — and hard-deletes
 * everything else. It is not `DELETE FROM orders`: the financial record
 * survives with its personal data overwritten and `anonymized_at` set.
 *
 * Runs synchronously inside one transaction, with the user row and its
 * orders locked: the self-service flow logs the customer out and tells them
 * it is done, so it must be done, and an erasure racing an order-status
 * change or a second erasure request must not interleave.
 *
 * Idempotent: only orders with `anonymized_at IS NULL` are touched, and a
 * re-run after the user row is gone finds nothing to do.
 *
 * `coupon_redemptions` is deliberately left alone — its `email_hash` is
 * peppered pseudonymisation whose retention is defensible under fraud
 * prevention, and clearing it would silently reopen a one-per-customer
 * coupon to a re-registering person. `docs/reference/write-rules/gdpr.md`.
 *
 * Authorizes `erase` on the target `User` when an actor is given (the
 * Filament path); the self-service component passes no actor because a
 * customer erasing themselves needs no permission.
 */
final class EraseCustomer
{
    private const REDACTED = '[erased]';

    public function handle(User $user, ?User $actor = null): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('erase', $user);
        }

        DB::transaction(function () use ($user): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->anonymiseOrders($locked);
            $this->anonymiseReviews($locked);
            $this->deleteMarketingData($locked);

            // forceDelete, not delete: the soft-delete scope is deactivation.
            // The cascadeOnDelete FKs take addresses, carts, cart_items and
            // wishlist_items with it; nullOnDelete nulls the rest.
            $locked->forceDelete();
        });
    }

    private function anonymiseOrders(User $user): void
    {
        $orders = Order::query()
            ->where('user_id', $user->getKey())
            ->whereNull('anonymized_at')
            ->lockForUpdate()
            ->get();

        foreach ($orders as $order) {
            $orderId = $order->getKey();

            if (! is_int($orderId)) {
                throw new InvalidArgumentException('Order::getKey() returned a non-integer value.');
            }

            $order->update([
                'email' => "erased-{$orderId}@anonymized.invalid",
                'first_name' => self::REDACTED,
                'last_name' => self::REDACTED,
                'phone' => '',
                'customer_note' => null,
                'anonymized_at' => now(),
            ]);

            OrderAddress::query()
                ->where('order_id', $order->getKey())
                ->update([
                    'first_name' => self::REDACTED,
                    'last_name' => self::REDACTED,
                    'phone' => '',
                    'city' => self::REDACTED,
                    'postcode' => self::REDACTED,
                    'street' => null,
                    'courier_office_code' => null,
                    'courier_office_name' => null,
                    // country is kept: a 2-letter code does not identify a
                    // person and is the place-of-supply for the VAT record.
                ]);
        }
    }

    private function anonymiseReviews(User $user): void
    {
        // user_id is nulled by the FK on forceDelete; the name snapshot is
        // ours to overwrite. The body and rating stay — an erased review is
        // an anonymous review, not a deleted one (ADR-0019).
        ProductReview::query()
            ->where('user_id', $user->getKey())
            ->update(['author_name' => 'Anonymous']);
    }

    private function deleteMarketingData(User $user): void
    {
        // Matched by id or by address: a guest who subscribed or wrote in
        // before registering has a null user_id on those rows.
        NewsletterSubscriber::query()
            ->where('user_id', $user->getKey())
            ->orWhere('email', $user->email)
            ->delete();

        ContactMessage::query()
            ->where('user_id', $user->getKey())
            ->orWhere('email', $user->email)
            ->delete();
    }
}
