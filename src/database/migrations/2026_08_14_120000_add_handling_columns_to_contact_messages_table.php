<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every column on `contact_messages` belonged to the sender, so the only thing
 * an administrator could change on the edit screen was the customer's own
 * words. `ContactMessagePolicy::update()` already described the intended
 * action — "marking handled or attaching an internal note, not rewriting the
 * message" — but the columns it assumed did not exist.
 *
 * `handled_at` is a nullable timestamp rather than a boolean: when a message
 * was dealt with is worth more than that it was, and null already means "not
 * yet". `internal_note` is staff-facing only and is never rendered on the
 * storefront.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->timestamp('handled_at')->nullable()->after('message');
            $table->text('internal_note')->nullable()->after('handled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->dropColumn(['handled_at', 'internal_note']);
        });
    }
};
