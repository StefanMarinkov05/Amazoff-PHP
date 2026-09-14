<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable and stays nullable: every order created before this migration
     * has no carrier, and `orders` is append-only after the schema freeze —
     * a NOT NULL column here would need a backfill this migration cannot
     * honestly perform. The customer-facing "you must pick a carrier" rule
     * lives in `CheckoutPage::rules()`, not in this column.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('carrier_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carrier_id');
        });
    }
};
