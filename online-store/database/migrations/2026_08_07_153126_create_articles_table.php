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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained();
            $table->string('title', 100);
            $table->text('content');
            $table->string('slug')->unique();
            $table->enum('status', ["draft","published","archived","scheduled"]);
            $table->string('summary', 255)->nullable();
            $table->boolean('featured')->default(false);
            $table->string('seo_title', 100)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->timestamp('published_at');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('article_category_id')->constrained();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
