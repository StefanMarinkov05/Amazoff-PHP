<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArticleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property ArticleStatus $status
 */
class Article extends Model
{
    /** Directory within the default `public` disk. Mirrors `ProductImage::DIRECTORY`. */
    public const IMAGE_DIRECTORY = 'articles';

    /** See `ProductImage::ACCEPTED_MIME_TYPES` — same reasoning, no SVG in user-facing content. */
    public const IMAGE_ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_MAX_SIZE_KB = 2048;

    public const IMAGE_MIN_WIDTH_PX = 400;

    public const IMAGE_MIN_HEIGHT_PX = 400;

    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'article_category_id',
        'title',
        'slug',
        'summary',
        'content',
        'main_image_path',
        'status',
        'featured',
        'seo_title',
        'seo_description',
        'published_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'author_id' => 'integer',
            'article_category_id' => 'integer',
            'status' => ArticleStatus::class,
            'featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function articleCategory(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class);
    }
}
