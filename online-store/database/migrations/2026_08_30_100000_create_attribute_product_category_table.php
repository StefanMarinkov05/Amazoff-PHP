<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An attribute with no rows here is allowed for every category — this is an
 * allow-list an admin opts an attribute into, not a mandatory classification
 * forced onto the ~40 attributes that already existed before it. See
 * App\Support\ResolveAllowedAttributes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_product_category', function (Blueprint $table) {
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['attribute_id', 'product_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_product_category');
    }
};
