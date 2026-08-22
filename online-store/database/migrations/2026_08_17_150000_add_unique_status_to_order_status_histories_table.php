<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backstop for `TransitionOrderStatus`'s idempotency, not the mechanism.
 *
 * `OrderStatus::allowedTransitions()` describes a directed acyclic graph
 * (`tests/Unit/Enums/TransitionMatrixTest.php`, "keeps the order status graph
 * acyclic") — no order can ever re-enter a status it already left. That is
 * what makes `UNIQUE(order_id, new_status)` a true invariant rather than an
 * accidental one: a second history row for the same order and status can only
 * mean two concurrent transitions raced past the `orders` row lock, not a
 * legitimate second visit.
 *
 * The lock is what prevents that in the ordinary case; this constraint is
 * what turns a lock failure into a loud `QueryException` instead of a
 * duplicate history row nobody notices. See
 * `explanation/concurrency-and-locking.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->unique(['order_id', 'new_status'], 'order_status_histories_order_id_new_status_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropUnique('order_status_histories_order_id_new_status_unique');
        });
    }
};
