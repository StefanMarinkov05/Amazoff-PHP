<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Omnibus Directive (EU) 2019/2161 prior-price display (ADR-0021; BG ЗЗП чл.
 * 6б). When a product shows a reduced price, the storefront must also show the
 * lowest price it was sold at in the 30 days before the reduction.
 *
 * One row per observation of a product's **effective** selling price (the
 * discounted price while its window is open, otherwise the regular price).
 * Written by `RecordPriceObservation` — from `CreateProduct` / `UpdateProduct`
 * whenever a price field moves, and once a day by `products:snapshot-prices`
 * so a scheduled discount window opening or closing is captured even without
 * an admin edit. `reference/write-rules/product.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamp('recorded_at');
            $table->timestamps();

            // The prior-price query is "MIN(price) for this product in a date
            // range" — this index serves it directly.
            $table->index(['product_id', 'recorded_at']);
        });

        // Seed a starting timeline for products that already exist, so the
        // prior-price line is meaningful before 30 days of the daily snapshot
        // have accrued: one row at `regular_price` 31 days ago (the assumed
        // pre-sale price) and one at today's effective price. The daily
        // `products:snapshot-prices` run takes over from here. New products
        // get their first row from `CreateProduct`.
        $now = Carbon::now();
        $priorDate = $now->copy()->subDays(31);

        DB::table('products')
            ->select(['id', 'regular_price', 'discount_price', 'discount_starts_at', 'discount_ends_at'])
            ->orderBy('id')
            ->chunk(500, function ($products) use ($now, $priorDate): void {
                $rows = [];

                foreach ($products as $product) {
                    $windowOpen = ($product->discount_starts_at === null || $product->discount_starts_at <= $now)
                        && ($product->discount_ends_at === null || $product->discount_ends_at >= $now);
                    $effective = ($product->discount_price !== null && $windowOpen)
                        ? $product->discount_price
                        : $product->regular_price;

                    $rows[] = [
                        'product_id' => $product->id,
                        'price' => $product->regular_price,
                        'recorded_at' => $priorDate,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $rows[] = [
                        'product_id' => $product->id,
                        'price' => $effective,
                        'recorded_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('product_price_history')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
    }
};
