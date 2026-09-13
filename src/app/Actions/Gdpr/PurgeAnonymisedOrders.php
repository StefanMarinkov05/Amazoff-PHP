<?php

declare(strict_types=1);

namespace App\Actions\Gdpr;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes anonymised orders whose accounting-retention period has expired
 * (ADR-0019). After anonymisation the row holds no personal data — only the
 * accounting basis keeps it, and once `config('gdpr.order_retention_years')`
 * has passed since `anonymized_at`, that basis is gone too.
 *
 * A plain `delete()`: every child of `orders` (`order_items`,
 * `order_addresses`, `order_status_histories`, `payments`, `shipments`,
 * `coupon_redemptions`) is `cascadeOnDelete`, so the row and its whole
 * subtree go in one statement.
 *
 * Returns null when retention is disabled (the config value is null), so
 * the command can say "disabled" rather than "0 purged".
 */
final class PurgeAnonymisedOrders
{
    /** @return int|null rows deleted, or null if retention is disabled */
    public function handle(): ?int
    {
        $years = config('gdpr.order_retention_years');

        if ($years === null) {
            return null;
        }

        if (! is_numeric($years) || (int) $years < 1) {
            throw new RuntimeException(
                'config(gdpr.order_retention_years) must be a positive integer or null.'
            );
        }

        $cutoff = now()->subYears((int) $years);

        return DB::transaction(function () use ($cutoff): int {
            $orders = Order::query()
                ->whereNotNull('anonymized_at')
                ->where('anonymized_at', '<', $cutoff)
                ->lockForUpdate()
                ->get();

            $count = 0;

            foreach ($orders as $order) {
                $order->delete();
                $count++;
            }

            return $count;
        });
    }
}
