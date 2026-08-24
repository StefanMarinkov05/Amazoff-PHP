<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops `orders.payment_status`, which held a second copy of a fact
     * `payments.status` already owns.
     *
     * `CreateOrder` wrote it once, at creation, and **nothing in `app/` ever
     * wrote it again** — while `TransitionPaymentStatus` moved
     * `payments.status` independently. So the two diverged the moment a
     * payment was paid or refunded, and the stale one was what `OrdersTable`
     * and `OrderInfolist` displayed and filtered on: a payment reading `paid`
     * with its order still reporting `pending`.
     *
     * §20 forbids storing `inventories.available()` for exactly this reason —
     * a derivable fact kept in a column goes wrong the first time something
     * writes one and not the other. This is the same shape, and the same
     * resolution: derive it. `Order::paymentStatus()` now reads through the
     * `payment` relation, and the panel eager-loads and filters through the
     * relationship rather than the column.
     *
     * Deliberately **not** backfilled before dropping. The column's values
     * are wrong wherever a payment moved, and right only where nothing
     * happened; copying them onto `payments` would overwrite correct data
     * with stale data. `payments.status` was always the accurate side.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Recreated with its original definition. The value is not
            // restored — it cannot be, the source of truth is `payments`.
            $table->enum('payment_status', [
                'pending',
                'processing',
                'paid',
                'failed',
                'cancelled',
                'refunded',
                'partially_refunded',
            ])->after('status');
        });
    }
};
