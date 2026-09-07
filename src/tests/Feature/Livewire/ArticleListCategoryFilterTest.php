<?php

declare(strict_types=1);

use App\Enums\ArticleStatus;
use App\Livewire\Journal\ArticleList;
use App\Models\Article;
use App\Models\ArticleCategory;
use Livewire\Livewire;

/*
 * The fourth instance of this project's numeric-#[Url]-hydration incident
 * (docs/how-to/test-for-input-crashes.md): ProductDetails::$quantity,
 * ::$variationId, ProductList::$brandId, and now this one — found by the
 * full-codebase sweep the third instance's writeup recommended, and it was
 * already crashing live (?categoryId=99999999999999999999999999999999,
 * confirmed via curl) before this file or the fix existed.
 */

function publishedArticle(?ArticleCategory $category = null): Article
{
    /** @var Article $article */
    $article = Article::factory()->create([
        'status' => ArticleStatus::Published,
        'published_at' => now()->subDay(),
        'article_category_id' => $category?->id,
    ]);

    return $article;
}

it('does not crash on a category id too large for PHP to represent as an int', function (): void {
    publishedArticle();

    Livewire::withQueryParams(['categoryId' => '99999999999999999999999999999999'])
        ->test(ArticleList::class)
        ->assertOk();
});

it('does not crash on a non-numeric category id', function (): void {
    publishedArticle();

    Livewire::withQueryParams(['categoryId' => 'not-a-number'])
        ->test(ArticleList::class)
        ->assertOk();
});

it('does not crash on an XSS-shaped category id and does not reflect it unescaped', function (): void {
    publishedArticle();

    Livewire::withQueryParams(['categoryId' => '<script>alert(1)</script>'])
        ->test(ArticleList::class)
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('finds no articles for a well-formed but non-existent category id, without crashing', function (): void {
    publishedArticle();

    $ids = Livewire::withQueryParams(['categoryId' => '999999'])
        ->test(ArticleList::class)
        ->assertOk()
        ->viewData('articles')
        ->pluck('id')
        ->all();

    expect($ids)->toBe([]);
});

it('applies no category filter for a malformed id, rather than matching nothing', function (): void {
    $article = publishedArticle();

    $ids = Livewire::withQueryParams(['categoryId' => 'not-a-number'])
        ->test(ArticleList::class)
        ->assertOk()
        ->viewData('articles')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($article->id);
});

it('filters correctly with a real category id, proving the fix did not break the filter', function (): void {
    $category = ArticleCategory::factory()->create();
    $matching = publishedArticle($category);
    $other = publishedArticle();

    $ids = Livewire::withQueryParams(['categoryId' => (string) $category->id])
        ->test(ArticleList::class)
        ->assertOk()
        ->viewData('articles')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($matching->id)
        ->and($ids)->not->toContain($other->id);
});
