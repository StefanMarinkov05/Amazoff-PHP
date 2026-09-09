<?php

declare(strict_types=1);

use App\Enums\NewsletterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter double opt-in (ePrivacy Art. 13, ADR-0019).
 *
 * - `status` gains `pending` — restated from `NewsletterStatus::values()`
 *   the way 2026_09_07_183000 restated every other enum column, so the DB
 *   list stays derived from the PHP enum. Raw `MODIFY` because this project
 *   has no `doctrine/dbal`; the previous list was
 *   `ENUM('subscribed','unsubscribed')`, verified against
 *   `information_schema.columns` before writing this.
 * - `confirmation_token` — a random 64-char string on the row, the key both
 *   the confirm and the unsubscribe links match on. Kept for the life of
 *   the row so an unsubscribe link in an old email still works.
 * - `confirmed_at` — when the opt-in was completed. `null` while `pending`.
 *
 * Append-only, post schema-freeze — a new migration, never an edit to
 * 2026_08_09_094353.
 */
return new class extends Migration
{
    public function up(): void
    {
        $values = implode(',', array_map(
            static fn (string $v): string => "'".$v."'",
            NewsletterStatus::values(),
        ));

        DB::statement("ALTER TABLE `newsletter_subscribers` MODIFY `status` ENUM({$values}) NOT NULL");

        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            $table->char('confirmation_token', 64)->nullable()->unique()->after('status');
            $table->timestamp('confirmed_at')->nullable()->after('subscribed_at');
        });

        // Existing rows predate double opt-in; treat them as already
        // confirmed rather than silently un-subscribing a live list.
        DB::table('newsletter_subscribers')
            ->where('status', NewsletterStatus::Subscribed->value)
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            $table->dropUnique(['confirmation_token']);
            $table->dropColumn(['confirmation_token', 'confirmed_at']);
        });

        DB::statement("UPDATE `newsletter_subscribers` SET `status` = 'unsubscribed' WHERE `status` = 'pending'");
        DB::statement("ALTER TABLE `newsletter_subscribers` MODIFY `status` ENUM('subscribed','unsubscribed') NOT NULL");
    }
};
