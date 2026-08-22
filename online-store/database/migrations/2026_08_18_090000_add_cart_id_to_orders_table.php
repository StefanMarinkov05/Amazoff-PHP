<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One order per cart. `UNIQUE(cart_id)`, nullable — the CLAUDE.md
 * idempotency shape (a constraint plus a caught violation, never
 * check-then-act), matching `coupon_redemptions`'s `UNIQUE(coupon_id,
 * order_id)` and `cart_items`'s `UNIQUE(cart_id, product_variation_id)`.
 *
 * Deliberately no foreign key. `write-rules/order.md` already establishes
 * that `CreateOrder` has no dependency on the source cart surviving — no
 * `order_items` FK to `carts` either — and a `nullOnDelete()` FK here would
 * only reopen a small window: a cart deleted and its id reused (MySQL
 * recycles auto-increment ids only after a restart, but nothing here should
 * rely on that) could otherwise collide with a stale reference. A plain
 * column with no FK avoids the question entirely.
 *
 * Nullable because every `Order` row created outside `CreateOrder` — every
 * factory, every seeder, every other test's fixture — has no cart to
 * reference, and MySQL's `UNIQUE` treats each `NULL` as distinct, so those
 * rows never collide with each other or with a real checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('cart_id')->nullable()->after('user_id');
            $table->unique('cart_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['cart_id']);
            $table->dropColumn('cart_id');
        });
    }
};
