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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained();
            $table->string('shipment_number', 100)->nullable();
            $table->string('tracking_number', 100)->nullable()->unique();
            $table->enum('status', ['pending', 'shipped', 'in_transit', 'delivered', 'returned', 'cancelled']);
            $table->string('raw_status', 100)->nullable();
            $table->string('label_path', 255)->nullable();
            $table->string('courier_tracking_url', 255)->nullable();
            $table->decimal('cod_amount', 10, 2)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
