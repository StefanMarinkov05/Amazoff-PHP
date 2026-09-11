<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Content\PublishArticle;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use RuntimeException;

/**
 * Turns one article fixture document into a row, then moves it to its target
 * status through `PublishArticle` — the same shape `FixtureLoader` uses for
 * products: insert the aggregate directly, drive lifecycle through the
 * Action that owns it.
 *
 * `article_fixture-format.md` is the document shape. Slug maps are built
 * once per loader instance and lazily, same reasoning as `FixtureLoader`.
 */
final class ArticleFixtureLoader
{
    /** @var array<string, int>|null */
    private ?array $categories = null;

    /** @var array<string, int>|null */
    private ?array $tags = null;

    /** @var array<string, int>|null */
    private ?array $products = null;

    /** @var array<string, int>|null */
    private ?array $authors = null;

    public function __construct(private readonly PublishArticle $publishArticle) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function loadArticle(array $document): Article
    {
        $author = $this->authorId(self::string($document, 'author'));

        /** @var Article $article */
        $article = Article::create([
            'author_id' => $author,
            'article_category_id' => isset($document['category'])
                ? $this->categoryId(self::string($document, 'category'))
                : null,
            'title' => self::string($document, 'title'),
            'slug' => self::string($document, 'slug'),
            'summary' => isset($document['summary']) ? self::string($document, 'summary') : null,
            'content' => self::string($document, 'content'),
            'main_image_path' => isset($document['main_image_path']) ? self::string($document, 'main_image_path') : null,
            // Every article is created Draft regardless of what the fixture
            // asks for. PublishArticle is the only writer of status —
            // creating it pre-published would bypass the one Action that
            // enforces ArticleStatus's matrix and stamps published_at.
            'status' => ArticleStatus::Draft,
            'featured' => (bool) ($document['featured'] ?? false),
            'seo_title' => isset($document['seo_title']) ? self::string($document, 'seo_title') : null,
            'seo_description' => isset($document['seo_description']) ? self::string($document, 'seo_description') : null,
        ]);

        $this->attachTags($article, self::stringList($document, 'tags'));
        $this->attachProducts($article, self::stringList($document, 'related_products'));

        $status = $document['status'] ?? 'draft';

        if (! is_string($status) && ! is_int($status)) {
            throw new RuntimeException('Article fixture status must be a string or int.');
        }

        $target = ArticleStatus::from($status);

        if ($target !== ArticleStatus::Draft) {
            $this->driveToStatus($article, $target);
        }

        return $article->refresh();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;

        if (! is_scalar($value)) {
            throw new RuntimeException("Article fixture field [{$key}] must be a scalar value.");
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private static function stringList(array $document, string $key): array
    {
        $value = $document[$key] ?? [];

        if (! is_array($value)) {
            throw new RuntimeException("Article fixture field [{$key}] must be a list.");
        }

        return array_map(static function (mixed $item) use ($key): string {
            if (! is_scalar($item)) {
                throw new RuntimeException("Article fixture field [{$key}] must contain only scalar values.");
            }

            return (string) $item;
        }, array_values($value));
    }

    /**
     * `ArticleStatus`'s matrix has no `Draft => Archived` edge — an article
     * must pass through `Published` first (§22's lifecycle: draft, scheduled
     * or published, then archived). A fixture asking for `archived` is
     * therefore two calls, not one, and this is the one place that detour is
     * decided rather than left for whoever authors the next fixture to
     * rediscover by hitting `ArticleTransitionNotAllowedException`.
     */
    private function driveToStatus(Article $article, ArticleStatus $target): void
    {
        if ($target === ArticleStatus::Archived) {
            $this->publishArticle->handle($article, ArticleStatus::Published, null);
        }

        $this->publishArticle->handle($article, $target, null);
    }

    /**
     * @param  list<string>  $tagSlugs
     */
    private function attachTags(Article $article, array $tagSlugs): void
    {
        if ($tagSlugs === []) {
            return;
        }

        $article->tags()->attach(array_map(
            fn (string $slug): int => $this->tagId($slug),
            $tagSlugs,
        ));
    }

    /**
     * @param  list<string>  $productSlugs
     */
    private function attachProducts(Article $article, array $productSlugs): void
    {
        if ($productSlugs === []) {
            return;
        }

        $article->products()->attach(array_map(
            fn (string $slug): int => $this->productId($slug),
            $productSlugs,
        ));
    }

    private function authorId(string $email): int
    {
        if ($this->authors === null) {
            /** @var array<string, int> $authors */
            $authors = User::query()->pluck('id', 'email')->all();
            $this->authors = $authors;
        }

        return $this->authors[$email]
            ?? throw new RuntimeException("Fixture references unknown author email [{$email}].");
    }

    private function categoryId(string $slug): int
    {
        if ($this->categories === null) {
            /** @var array<string, int> $categories */
            $categories = ArticleCategory::query()->pluck('id', 'slug')->all();
            $this->categories = $categories;
        }

        return $this->categories[$slug]
            ?? throw new RuntimeException("Fixture references unknown article category slug [{$slug}].");
    }

    private function tagId(string $slug): int
    {
        if ($this->tags === null) {
            /** @var array<string, int> $tags */
            $tags = Tag::query()->pluck('id', 'slug')->all();
            $this->tags = $tags;
        }

        return $this->tags[$slug]
            ?? throw new RuntimeException("Fixture references unknown tag slug [{$slug}].");
    }

    private function productId(string $slug): int
    {
        if ($this->products === null) {
            /** @var array<string, int> $products */
            $products = Product::query()->pluck('id', 'slug')->all();
            $this->products = $products;
        }

        return $this->products[$slug]
            ?? throw new RuntimeException("Fixture references unknown product slug [{$slug}].");
    }
}
