<?php

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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('set null');
            $table->string('serial_number')->unique();
            $table->enum('status', ["pending","preparing","delivering","delivered","completed","cancelled","refunded"]);
            $table->enum('payment_status', ["pending","processing","paid","failed","cancelled","refunded","partially_refunded"]);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('shipping_amount', 10, 2)->nullable();
            $table->foreignId('billing_address_id')->constrained()->nullable()->onDelete('set null');
            $table->foreignId('delivery_address_id')->constrained()->nullable()->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
