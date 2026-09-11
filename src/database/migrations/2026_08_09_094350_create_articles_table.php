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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('article_category_id')->nullable()->constrained();
            $table->string('title', 100);
            $table->string('slug', 100)->unique();
            $table->string('summary', 255)->nullable();
            $table->text('content');
            $table->string('main_image_path', 255)->nullable();
            $table->enum('status', ['draft', 'published', 'scheduled', 'archived']);
            $table->boolean('featured')->default(false);
            $table->string('seo_title', 100)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->timestamp('published_at')->nullable();
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
