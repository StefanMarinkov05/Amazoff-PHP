<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds `disputed` to every enum column that stores a `PaymentStatus`.
     *
     * A customer can dispute a charge through their bank at any point after
     * the money moved. Before this, `charge.dispute.created` had nowhere to
     * land: the payment stayed `Paid`, the order shipped, and the only trace
     * was whatever an operator happened to notice in the Stripe dashboard.
     *
     * ## Three columns, not one
     *
     * `payments.status` is the obvious one. `payment_events.status_before`
     * and `status_after` are the trap: `HandleStripeWebhookEvent` writes the
     * event row *first*, inside the same transaction as the status change,
     * so a `payments.status` that accepted `disputed` while
     * `payment_events` did not would fail on the event insert and roll the
     * whole thing back — the dispute would be silently dropped and Stripe
     * would retry it forever.
     *
     * ## Raw ALTER rather than Doctrine
     *
     * Laravel's schema builder cannot modify an enum's allowed values
     * without `doctrine/dbal`, which this project does not install. The
     * column definitions are restated in full because MySQL's `MODIFY`
     * replaces the whole definition — every existing value is preserved in
     * the list, and the `NOT NULL`/`NULL` and default of each column matches
     * `create_payments_table` and `create_payment_events_table`.
     *
     * Append-only, per CLAUDE.md: the original migrations are untouched.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `payments` MODIFY `status` ENUM(
                'pending','processing','paid','failed','cancelled','refunded','partially_refunded','disputed'
            ) NOT NULL"
        );

        DB::statement(
            "ALTER TABLE `payment_events` MODIFY `status_before` ENUM(
                'pending','processing','paid','failed','cancelled','refunded','partially_refunded','disputed'
            ) NULL"
        );

        DB::statement(
            "ALTER TABLE `payment_events` MODIFY `status_after` ENUM(
                'pending','processing','paid','failed','cancelled','refunded','partially_refunded','disputed'
            ) NOT NULL"
        );
    }

    /**
     * Reversible only while no row actually holds `disputed`.
     *
     * MySQL turns a value that is no longer in the enum into `''` rather
     * than refusing, so rolling back with disputes recorded would quietly
     * corrupt them. Refused loudly instead — a rollback in that state is a
     * data-loss event, not a schema change.
     */
    public function down(): void
    {
        $disputed = DB::table('payments')->where('status', 'disputed')->count()
            + DB::table('payment_events')->where('status_after', 'disputed')->count()
            + DB::table('payment_events')->where('status_before', 'disputed')->count();

        if ($disputed > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$disputed} row(s) hold 'disputed', and MySQL would silently blank them."
            );
        }

        DB::statement(
            "ALTER TABLE `payments` MODIFY `status` ENUM(
                'pending','processing','paid','failed','cancelled','refunded','partially_refunded'
            ) NOT NULL"
        );

        DB::statement(
            "ALTER TABLE `payment_events` MODIFY `status_before` ENUM(
                'pending','processing','paid','failed','cancelled','refunded','partially_refunded'
            ) NULL"
        );

        DB::statement(
            "ALTER TABLE `payment_events` MODIFY `status_after` ENUM(
                'pending','processing','paid','failed','cancelled','refunded','partially_refunded'
            ) NOT NULL"
        );
    }
};
