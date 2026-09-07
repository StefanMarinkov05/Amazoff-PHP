<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('previous_status', ['new', 'awaiting_payment', 'paid', 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered', 'cancelled', 'returned', 'refunded'])->nullable();
            $table->enum('new_status', ['new', 'awaiting_payment', 'paid', 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered', 'cancelled', 'returned', 'refunded']);
            $table->string('reason', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
