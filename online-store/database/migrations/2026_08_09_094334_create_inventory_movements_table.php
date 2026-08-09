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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('movement_type', ['initial_stock', 'new_delivery', 'order_reservation', 'completed_sale', 'reservation_release', 'customer_return', 'damaged_product', 'manual_correction']);
            $table->integer('quantity');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
