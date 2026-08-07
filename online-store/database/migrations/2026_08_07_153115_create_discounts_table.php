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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('type', ["percentage","product","order"]);
            $table->decimal('value', 5, 2);
            $table->string('code', 50)->nullable();
            $table->string('description', 100)->nullable();
            $table->decimal('minimum_order_value', 10, 2)->nullable();
            $table->timestamp('start_date');
            $table->timestamp('end_date')->nullable();
            $table->integer('total_usage_limit')->nullable();
            $table->integer('usage_limit_per_customer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
