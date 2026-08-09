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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 50)->unique();
            $table->string('email', 100);
            $table->string('phone', 30);
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->enum('status', ['new', 'awaiting_payment', 'paid', 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered', 'cancelled', 'returned', 'refunded']);
            $table->enum('payment_status', ['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded']);
            $table->enum('payment_method', ['stripe', 'cash_on_delivery']);
            $table->char('currency', 3)->default('EUR');
            $table->decimal('subtotal_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('shipping_amount', 10, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->boolean('invoice_required')->default(false);
            $table->string('invoice_company', 150)->nullable();
            $table->string('invoice_vat_number', 30)->nullable();
            $table->string('invoice_eik', 20)->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->index('email');
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
