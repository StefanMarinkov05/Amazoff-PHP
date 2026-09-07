<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Finishes what `2026_09_07_183000_derive_enum_columns_from_php_enums`
     * started for `payments.status` and `payment_events.status_{before,after}`
     * — the three columns that migration deliberately left as hand-typed
     * literals, on the reasoning that `PaymentStatus::values()` already
     * produces the identical list so there was nothing to change at the
     * schema level. True, but it left exactly the trio that has already
     * drifted once (`2026_09_01_100000_add_disputed_to_payment_status_enums`
     * exists because `disputed` was added to `PaymentStatus` without these
     * three `ALTER TABLE`s following automatically) as the one place still
     * relying on a human noticing next time. This migration closes that gap
     * the same way the other 15 columns were closed.
     *
     * Every other enum-backed column in the schema now derives its list
     * from `EnumClass::values()`; these three are the last ones that didn't.
     *
     * ## What this does not change
     *
     * `PaymentStatus::values()` produces the exact same list these columns
     * already have — verified against `information_schema.columns` before
     * writing this migration. Purely a maintainability fix.
     *
     * ## Raw ALTER rather than Doctrine
     *
     * Same reason as every other migration in this series: Laravel's schema
     * builder cannot modify an enum's allowed values without
     * `doctrine/dbal`, which this project does not install.
     *
     * Append-only, per CLAUDE.md: the original migrations are untouched.
     */
    public function up(): void
    {
        $this->alter('payments', 'status', nullable: false);
        $this->alter('payment_events', 'status_before', nullable: true);
        $this->alter('payment_events', 'status_after', nullable: false);
    }

    /**
     * Reverses to the literal list `2026_09_01_100000` left these columns
     * with — the schema state that existed before this migration ran, not
     * "whatever PaymentStatus happens to hold now."
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `payments` MODIFY `status` ENUM('pending','processing','paid','failed','cancelled','refunded','partially_refunded','disputed') NOT NULL");
        DB::statement("ALTER TABLE `payment_events` MODIFY `status_before` ENUM('pending','processing','paid','failed','cancelled','refunded','partially_refunded','disputed') NULL");
        DB::statement("ALTER TABLE `payment_events` MODIFY `status_after` ENUM('pending','processing','paid','failed','cancelled','refunded','partially_refunded','disputed') NOT NULL");
    }

    private function alter(string $table, string $column, bool $nullable): void
    {
        $list = implode(',', array_map(static fn (string $v): string => "'{$v}'", PaymentStatus::values()));
        $null = $nullable ? 'NULL' : 'NOT NULL';

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$list}) {$null}");
    }
};
