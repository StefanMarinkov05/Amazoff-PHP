<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery price `CalculateDeliveryPrice` falls back to when the
     * carrier's own quote endpoint is unreachable — without a stored figure
     * an API outage has nothing to charge and checkout either fails
     * outright or ships free. Same placeholder-until-verified status as
     * `cod_fee`: plausible, not taken from a published tariff sheet.
     */
    public function up(): void
    {
        Schema::table('carriers', function (Blueprint $table): void {
            $table->decimal('base_delivery_price', 10, 2)->default(0)->after('cod_fee');
        });
    }

    public function down(): void
    {
        Schema::table('carriers', function (Blueprint $table): void {
            $table->dropColumn('base_delivery_price');
        });
    }
};
