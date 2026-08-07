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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->onDelete('cascade');
            $table->enum('movement_type', ["initial_stock","new_delivery","order_reservation","completed_sale","reservation_release","customer_return","damaged_product","manual_correction"]);
            $table->integer('quantity');
            $table->string('note')->nullable();
            $table->timestamp('movement_date');
            $table->timestamp('created_at');
            $table->foreignId('created_by')->constrained()->nullable();
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
