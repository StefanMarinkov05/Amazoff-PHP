<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BG couriers charge a separate cash-on-delivery handling fee on top of
     * shipping — it varies by carrier (Econt and Speedy price it
     * differently), not by product or payment method in the abstract, so it
     * belongs on `carriers` rather than `orders` or a config constant.
     * Nullable/zero means "no COD surcharge," true for every carrier until a
     * real tariff is entered; only relevant when `orders.payment_method` is
     * `cash_on_delivery`.
     */
    public function up(): void
    {
        Schema::table('carriers', function (Blueprint $table): void {
            $table->decimal('cod_fee', 10, 2)->default(0)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('carriers', function (Blueprint $table): void {
            $table->dropColumn('cod_fee');
        });
    }
};
