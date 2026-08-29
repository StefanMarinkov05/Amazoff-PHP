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
        $author = $this->authorId($document['author']);

        /** @var Article $article */
        $article = Article::create([
            'author_id' => $author,
            'article_category_id' => isset($document['category'])
                ? $this->categoryId($document['category'])
                : null,
            'title' => $document['title'],
            'slug' => $document['slug'],
            'summary' => $document['summary'] ?? null,
            'content' => $document['content'],
            'main_image_path' => $document['main_image_path'] ?? null,
            // Every article is created Draft regardless of what the fixture
            // asks for. PublishArticle is the only writer of status —
            // creating it pre-published would bypass the one Action that
            // enforces ArticleStatus's matrix and stamps published_at.
            'status' => ArticleStatus::Draft,
            'featured' => (bool) ($document['featured'] ?? false),
            'seo_title' => $document['seo_title'] ?? null,
            'seo_description' => $document['seo_description'] ?? null,
        ]);

        $this->attachTags($article, $document['tags'] ?? []);
        $this->attachProducts($article, $document['related_products'] ?? []);

        $target = ArticleStatus::from($document['status'] ?? 'draft');

        if ($target !== ArticleStatus::Draft) {
            $this->driveToStatus($article, $target);
        }

        return $article->refresh();
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
        $this->authors ??= User::query()->pluck('id', 'email')->all();

        return $this->authors[$email]
            ?? throw new RuntimeException("Fixture references unknown author email [{$email}].");
    }

    private function categoryId(string $slug): int
    {
        $this->categories ??= ArticleCategory::query()->pluck('id', 'slug')->all();

        return $this->categories[$slug]
            ?? throw new RuntimeException("Fixture references unknown article category slug [{$slug}].");
    }

    private function tagId(string $slug): int
    {
        $this->tags ??= Tag::query()->pluck('id', 'slug')->all();

        return $this->tags[$slug]
            ?? throw new RuntimeException("Fixture references unknown tag slug [{$slug}].");
    }

    private function productId(string $slug): int
    {
        $this->products ??= Product::query()->pluck('id', 'slug')->all();

        return $this->products[$slug]
            ?? throw new RuntimeException("Fixture references unknown product slug [{$slug}].");
    }
}
