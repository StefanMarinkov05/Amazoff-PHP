<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `times_used` is a second source of truth for a count the database cannot
 * keep in sync with `coupon_redemptions` — no constraint spans the two
 * tables. Both usage caps (`total_usage_limit`, `usage_limit_per_customer`)
 * are answered instead by `COUNT` over `coupon_redemptions` under
 * `lockForUpdate()` on the `coupons` row. `misc/coupon-actions-plan.md`,
 * decision 1.
 *
 * SQLite has no `DROP CONSTRAINT`, matching the guard in
 * `2026_08_11_094725_add_check_constraints_to_domain_tables.php` — the
 * constraint this drops was never created there under Pest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `coupons` DROP CONSTRAINT `chk_coupons_times_used_non_negative`');
        }

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('times_used');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->integer('times_used')->default(0);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `coupons` ADD CONSTRAINT `chk_coupons_times_used_non_negative` CHECK (times_used >= 0)');
        }
    }
};
