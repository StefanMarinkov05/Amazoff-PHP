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
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            // Owned line-item of the order: no meaning on its own.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Traceability only, not integrity: OrderAddress already snapshots
            // the address fields, so the source can be nulled out rather than
            // blocking deletion of an address the customer removes later.
            $table->foreignId('source_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->enum('type', ['billing', 'delivery']);
            $table->enum('delivery_type', ['address', 'office']);
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('phone', 30);
            $table->char('country', 2);
            $table->string('city', 50);
            $table->string('postcode', 20);
            $table->string('street', 150)->nullable();
            $table->string('courier_office_code', 50)->nullable();
            $table->string('courier_office_name', 150)->nullable();
            $table->unique(['order_id', 'type']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
