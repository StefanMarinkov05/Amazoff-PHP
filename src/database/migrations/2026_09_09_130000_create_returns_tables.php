<?php

declare(strict_types=1);

use App\Enums\ReturnStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer returns / 14-day right-of-withdrawal aggregate (ADR-0020,
 * implementing ADR-0019 §"The returns flow"; CRD Arts. 9–15).
 *
 * A genuinely new aggregate, so a new `Schema::create` rather than an edit to
 * a frozen migration — ADR-0019 §Consequences says the freeze covers editing
 * merged migrations only.
 *
 * `returns` (the model is `OrderReturn` — `Return` is a PHP reserved word and
 * cannot be a class name) is deliberately *not* coupled to `orders.status`:
 * see ADR-0020. "Delivered when" for the 14-day window is read from the
 * `order_status_histories` row for the `Delivered` transition, so there is no
 * `delivered_at` column here.
 *
 * `status` is `enum(ReturnStatus::values())` so the DB constraint stays
 * derived from the PHP enum, the convention
 * `2026_09_07_183000_derive_enum_columns_from_php_enums` established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ReturnStatus::values())->default(ReturnStatus::Requested->value)->index();
            // The customer's stated reason for withdrawing. Free text, so it
            // is purified before storage (ADR-0015) and nulled on erasure
            // (the same class of data as orders.customer_note) — ADR-0020.
            $table->text('reason');
            // Staff note recorded by ReviewReturn / RefundReturn.
            $table->text('resolution_note')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            // What RefundReturn actually sent back — goods value only; the
            // Stripe refund is capped and echoed by RefundPayment.
            $table->decimal('refunded_amount', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // One row per order line per return — a customer changing the
            // quantity edits the row, they do not add a second one.
            $table->unique(['return_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');
    }
};
