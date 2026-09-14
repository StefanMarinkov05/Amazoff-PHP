<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `demo_case_order`/`demo_case_label`: the staff-only "Demo order" sort
     * on `/catalogue` walks a curated sequence of products, each chosen to
     * showcase one distinguishable case (multi-image variations, an
     * impossible attribute combination, a parent-category product, out of
     * stock, `min_order_quantity > 1`, …) — `docs/reference/
     * demo-showcase-order.md` has the full list and the reasoning per case.
     *
     * Nullable and additive: every product not part of the curated set
     * carries both as `null` and is unaffected. `demo_case_order` is the
     * sort key (nulls last, enforced in `ProductList`, not by the column);
     * `demo_case_label` is what the product card shows, in demo mode only.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedSmallInteger('demo_case_order')->nullable()->after('is_featured');
            $table->string('demo_case_label', 100)->nullable()->after('demo_case_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['demo_case_order', 'demo_case_label']);
        });
    }
};
