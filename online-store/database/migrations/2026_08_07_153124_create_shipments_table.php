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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('carrier_id');
            $table->string('tracking_number')->unique();
            $table->enum('status', ["pending","shipped","in_transit","delivered","returned"]);
            $table->string('label_url')->nullable();
            $table->string('courier_tracking_url')->nullable();
            $table->foreignId('shipping_address_id')->nullable();
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 30);
            $table->string('street', 150);
            $table->string('city', 50);
            $table->string('postcode', 20)->nullable();
            $table->string('country', 30);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
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
